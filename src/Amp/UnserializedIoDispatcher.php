<?php

namespace Amp;

use Alert\Reactor;

class UnserializedIoDispatcher implements Dispatcher {
    
    const MAX_WORKER_ID = PHP_INT_MAX;
    const MAX_PROCEDURE_LENGTH = 255;
    
    private $reactor;
    private $callResultFactory;
    private $workers = [];
    private $workerLoadMap = [];
    private $callWorkerMap = [];
    
    private $poolSize;
    private $workerCmd;
    private $lastCallId = 0;
    private $lastWorkerId = 0;
    private $writeErrorsTo = STDERR;
    private $readGranularity = 65355;
    private $debug = TRUE;
    
    function __construct(Reactor $reactor, $workerCmd, $poolSize = 1, UnserializedCallResultFactory $crf = NULL) {
        $this->reactor = $reactor;
        $this->callResultFactory = $crf ?: new UnserializedCallResultFactory;
        $this->workerCmd = $workerCmd;
        $this->poolSize = $poolSize > 0 ? $poolSize : 1;
        
        for ($i=0; $i < $this->poolSize; $i++) {
            $this->spawnWorker();
        }
        
        // Protect from zombie worker processes if we fatal out in the main process
        register_shutdown_function([$this, '__destruct']);
    }
    
    private function spawnWorker() {
        if (($workerId = ++$this->lastWorkerId) >= self::MAX_WORKER_ID) {
            $this->lastWorkerId = 0;
        }
        
        $process = new Process($this->workerCmd, $this->writeErrorsTo);

        $worker = new Worker;
        $worker->id = $workerId;
        $worker->process = $process;
        $worker->parser = new FrameParser;
        $worker->writer = new FrameWriter($process->stdin);
        
        $readWatcher = $this->reactor->onReadable($process->stdout, function() use ($worker) {
            $this->doWorkerRead($worker);
        });
        
        $writeWatcher = $this->reactor->onWritable($process->stdin, function() use ($worker) {
            $this->doWorkerWrite($worker);
        }, $enableNow = FALSE);
        
        $worker->readWatcher = $readWatcher;
        $worker->writeWatcher = $writeWatcher;
        
        $this->workers[$workerId] = $worker;
        $this->workerLoadMap[$workerId] = 0;
    }
    
    /**
     * Asynchronously execute a procedure
     * 
     * @param callable $onResult The callback to process the async execution's CallResult
     * @param string $procedure  The function to execute asynchronously
     * @param string $workload   Optional data to pass as an argument to the procedure
     * 
     * @return string Returns the task's call ID
     */
    function call($onResult, $procedure, $workload = NULL) {
        if ($this->debug) {
            $this->validateCall($onResult, $procedure);
        }
        
        if (($callId = ++$this->lastCallId) === Call::MAX_ID) {
            $this->lastCallId = 0;
        }
        
        $callId = pack('N', $callId);
        
        $call = new Call;
        $call->id = $callId;
        $call->onResult = $onResult;
        $call->procedure = $procedure;
        $call->resultBuffer = '';
        
        $payload = $callId . Call::REQUEST . chr(strlen($procedure)) . $procedure . $workload;
        $call->frame = new Frame($opcode = Frame::OP_DATA_FIN, $payload);
        
        $this->allocateCall($call);
        
        return $callId;
    }
    
    private function validateCall($onResult, $procedure) {
        if (!is_callable($onResult)) {
            throw new \InvalidArgumentException;
        } elseif (!is_string($procedure)) {
            throw new \InvalidArgumentException;
        } elseif (strlen($procedure) > self::MAX_PROCEDURE_LENGTH) {
            throw new \RangeException(
                'Procedure name exceeds max allowable length: ' . self::MAX_PROCEDURE_LENGTH
            );
        }
    }
    
    private function allocateCall(Call $call) {
        asort($this->workerLoadMap);
        
        $workerId = key($this->workerLoadMap);
        
        $this->workerLoadMap[$workerId]++;
        $this->callWorkerMap[$call->id] = $workerId;
        
        $worker = $this->workers[$workerId];
        $worker->outstandingCalls[$call->id] = $call;
        $worker->writer->enqueueFrame($call->frame);
        
        $this->doWorkerWrite($worker);
    }
    
    private function doWorkerWrite(Worker $worker) {
        try {
            if ($worker->writer->write()) {
                $this->reactor->disable($worker->writeWatcher);
            } else {
                $this->reactor->enable($worker->writeWatcher);
            }
        } catch (ResourceException $e) {
            $this->onWorkerIoError($worker);
        }
    }
    
    private function doWorkerRead(Worker $worker) {
        $data = @fread($worker->process->stdout, $this->readGranularity);
        
        if ($data || $data === '0') {
            $worker->parser->bufferData($data);
            while ($frame = $worker->parser->parse()) {
                $this->receiveWorkerFrame($worker, $frame);
            }
        } elseif (!is_resource($worker->process->stdout) || @feof($worker->process->stdout)) {
            $this->onWorkerIoError($worker);
        }
    }
    
    private function receiveWorkerFrame(Worker $worker, Frame $frame) {
        $payload = $frame->getPayload();
        
        $callId = substr($payload, 0, 4);
        $callCode = $payload[4];
        $payload = substr($payload, 5);
        
        $call = $worker->outstandingCalls[$callId];
        
        switch ($callCode) {
            case Call::RESULT:
                $call->resultBuffer .= $payload;
                $this->doCallResult($worker, $call, $result = $call->resultBuffer, $error = NULL);
                break;
            case Call::RESULT_PART:
                // @TODO Allow for partial result user callbacks
                $call->resultBuffer .= $payload;
                break;
            case Call::RESULT_ERROR:
                $error = new DispatchException($payload);
                $this->doCallResult($worker, $call, $result = NULL, $error);
                break;
            default:
                throw new \UnexpectedValueException(
                    'Unexpected Call result code: ' . $callCode
                );
        }
    }
    
    private function doCallResult(Worker $worker, $call, $result, \Exception $error = NULL) {
        $callResult = $this->callResultFactory->make($call->id, $result, $error);
        $callback = $call->onResult;
        $callback($callResult);
        
        $this->workerLoadMap[$worker->id]--;
        
        unset(
            $this->callWorkerMap[$call->id],
            $worker->outstandingCalls[$call->id]
        );
    }
    
    private function onWorkerIoError(Worker $worker) {
        $fatalCallId = key($worker->outstandingCalls);
        $fatalCall = current($worker->outstandingCalls);
        $outstandingCalls = $worker->outstandingCalls;
        
        unset($outstandingCalls[$fatalCallId]);
        
        $this->callWorkerMap = array_diff_key($this->callWorkerMap, $outstandingCalls);
        $this->doCallResult($worker, $fatalCall, $result = NULL, new ResourceException(
            "Worker process {$worker->process->pid} died while invoking `{$fatalCall->procedure}`"
        ));
        $this->unloadWorker($worker);
        $this->spawnWorker();
        
        foreach ($outstandingCalls as $call) {
            $this->allocateCall($call);
        }
    }
    
    private function unloadWorker(Worker $worker) {
        $this->reactor->cancel($worker->readWatcher);
        $this->reactor->cancel($worker->writeWatcher);
        $worker->process->__destruct();
        
        unset(
            $this->workers[$worker->id],
            $this->workerLoadMap[$worker->id]
        );
    }
    
    function __destruct() {
        foreach ($this->workers as $worker) {
            $this->unloadWorker($worker);
        }
    }
    
}
