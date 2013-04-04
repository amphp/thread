<?php

namespace Amp\Async;

use Amp\Reactor;

class Dispatcher {
    
    const CALL        = 1;
    const CALL_RESULT = 2;
    const CALL_ERROR  = 3;
    
    private $reactor;
    private $workerSessionFactory;
    private $autoWriteSubscription;
    private $autoTimeoutSubscription;
    
    private $workerSessions = [];
    private $pendingCallCounts = [];
    private $workerSubscriptions = [];
    private $workerCallMap = [];
    private $callWorkerMap = [];
    private $availableWorkers = [];
    private $timeoutSchedule = [];
    private $onResultCallbacks = [];
    private $partialResults = [];
    private $writableWorkers;
    
    private $workerCmd;
    private $workerCwd;
    
    private $lastCallId = 0;
    private $maxCallId = 2147483647;
    private $pendingCalls = 0;
    private $callTimeout = 30;
    private $readTimeout = 60;
    private $autoWriteInterval = 0.025;
    private $timeoutCheckInterval = 1;
    private $writeErrorsTo = STDERR;
    private $notifyOnPartialResult = FALSE;
    private $isStarted = FALSE;
    private $chrCallCode;
    
    function __construct(Reactor $reactor, WorkerSessionFactory $wsf = NULL) {
        $this->reactor = $reactor;
        $this->workerSessionFactory = $wsf ?: new WorkerSessionFactory;
        $this->writableWorkers = new \SplObjectStorage;
        $this->chrCallCode = chr(self::CALL);
    }
    
    function setCallTimeout($seconds) {
        $this->callTimeout = filter_var($seconds, FILTER_VALIDATE_FLOAT, ['options' => [
            'min_range' => 0,
            'default' => 30
        ]]);
    }
    
    function setTimeoutCheckInterval($seconds) {
        $this->timeoutCheckInterval = filter_var($seconds, FILTER_VALIDATE_FLOAT, ['options' => [
            'min_range' => 0.1,
            'default' => 1
        ]]);
    }
    
    function setMaxCallId($seconds) {
        $this->maxCallId = filter_var($seconds, FILTER_VALIDATE_INT, ['options' => [
            'min_range' => 1,
            'max_range' => 2147483647,
            'default' => 2147483647
        ]]);
    }
    
    function setGranularity($bytes) {
        $this->workerSessionFactory->setGranularity($bytes);
    }
    
    function notifyOnPartialResult($boolFlag) {
        $this->notifyOnPartialResult = filter_var($boolFlag, FILTER_VALIDATE_BOOLEAN);
    }
    
    /**
     * Populate the worker process pool
     * 
     * Repeated calls to start have no effect after the first invocation.
     * 
     * @param int $poolSize The number of worker processes to maintain in the pool
     * @param string $cmd   The executable worker process command
     * @param string $cwd   An optional current working directory for worker processes. If not
     *                      specified workers will have the same cwd as the main process.
     * 
     * @return void
     */
    function start($poolSize, $cmd, $cwd = NULL) {
        if ($this->isStarted) {
            return;
        }
        
        $this->workerCmd = $cmd;
        $this->workerCwd = $cwd;
        
        for ($i=0; $i < $poolSize; $i++) {
            $this->spawnWorkerSession();
        }
        
        $this->autoWriteSubscription = $this->reactor->repeat(function() {
            foreach ($this->writableWorkers as $workerSession) {
                if ($this->write($workerSession)) {
                    $this->writableWorkers->detach($workerSession);
                }
            }
        }, $this->autoWriteInterval);
        
        if ($this->callTimeout) {
            $this->autoTimeoutSubscription = $this->reactor->repeat(function() {
                $this->autoTimeout();
            }, $this->timeoutCheckInterval);
        }
        
        $this->isStarted = TRUE;
    }
    
    private function spawnWorkerSession() {
        $workerSession = $this->workerSessionFactory->__invoke(
            $this->workerCmd,
            $this->writeErrorsTo,
            $this->workerCwd
        );
        
        $readSubscription = $this->reactor->onReadable(
            $workerSession->getReadPipe(),
            function($pipe, $trigger) use ($workerSession) { $this->read($workerSession, $trigger); },
            $this->readTimeout
        );
        
        $workerId = spl_object_hash($workerSession);
        
        $this->workerSessions[$workerId] = $workerSession;
        $this->pendingCallCounts[$workerId] = 0;
        $this->workerSubscriptions[$workerId] = $readSubscription;
    }
    
    /**
     * Asynchronously execute a procedure and handle the result using the $onResult callback
     * 
     * @param callable $onResult The callback to process the async execution's CallResult
     * @param string $procedure  The function to execute asynchronously
     * @param string $workload   The data to pass as an argument to the procedure
     * 
     * @return string Returns the task's call ID
     */
    function call(callable $onResult, $procedure, $workload = NULL) {
        if (($callId = ++$this->lastCallId) == $this->maxCallId) {
            $this->lastCallId = 0;
        }
        
        $callId = pack('N', $callId);
        
        $this->onResultCallbacks[$callId] = $onResult;
        $this->partialResults[$callId] = '';
        
        if ($this->callTimeout) {
            $this->timeoutSchedule[$callId] = (time() + $this->callTimeout);
        }
        
        asort($this->pendingCallCounts);
        
        $workerId = key($this->pendingCallCounts);
        $workerSession = $this->workerSessions[$workerId];
        
        $this->pendingCalls++;
        $this->pendingCallCounts[$workerId]++;
        $this->workerCallMap[$workerId][$callId] = TRUE;
        $this->callWorkerMap[$callId] = $workerId;
        
        // @TODO validate that procLen <= 255
        $procLen = chr(strlen($procedure));
        $payload = $callId . $this->chrCallCode . $procLen . $procedure . $workload;
        $callFrame = new Frame($fin = 1, $rsv = 0, $opcode = Frame::OP_DATA, $payload);
        
        if (!$this->write($workerSession, $callFrame)) {
            $this->writableWorkers->attach($workerSession);
        }
        
        return $callId;
    }
    
    private function read(WorkerSession $workerSession, $triggeredBy) {
        if ($triggeredBy !== Reactor::TIMEOUT) {
            try {
                while ($frameArr = $workerSession->parse()) {
                    $this->receiveParsedFrame($workerSession, $frameArr);
                }
            } catch (ResourceException $e) {
                $this->handleBrokenProcessPipe($workerSession, $e);
            }
        }
    }
    
    private function receiveParsedFrame(WorkerSession $workerSession, array $frameArr) {
        list($isFin, $rsv, $opcode, $payload) = $frameArr;
        
        $callId = substr($payload, 0, 4);
        
        if (!isset($this->partialResults[$callId])) {
            return;
        }
        
        $callCode = ord($payload[4]);
        $payload = substr($payload, 5);
        
        if (!$isFin && $this->notifyOnPartialResult) {
            // No callCode check is made here because error results should always set the FIN bit
            $callResult = new CallResult($callId, $payload, $error = NULL, 0);
            $callback = $this->onResultCallbacks[$callId];
            $callback($callResult);
            return;
        } elseif ($isFin) {
            $payload = $this->partialResults[$callId] . $payload;
        } else {
            $this->partialResults[$callId] .= $payload;
            return;
        }
        
        if ($callCode == self::CALL_RESULT) {
            $callResult = new CallResult($callId, $payload, $error = NULL, 1);
        } elseif ($callCode == self::CALL_ERROR) {
            $callResult = new CallResult($callId, NULL, new WorkerException($payload), 1);
        } else {
            throw new \UnexpectedValueException(
                'Unexpected data frame RSV value'
            );
        }
        
        $this->onResult($callId, $callResult);
    }
    
    private function onResult($callId, $callResult) {
        $callback = $this->onResultCallbacks[$callId];
        $workerId = $this->callWorkerMap[$callId];
        
        $this->pendingCallCounts[$workerId]--;
        
        unset(
            $this->timeoutSchedule[$callId],
            $this->onResultCallbacks[$callId],
            $this->partialResults[$callId],
            $this->callWorkerMap[$callId],
            $this->workerCallMap[$workerId][$callId]
        );
        
        $callback($callResult);
    }
    
    private function handleBrokenProcessPipe(WorkerSession $workerSession, \Exception $e) {
        $workerId = spl_object_hash($workerSession);
        
        if (!empty($this->workerCallMap[$workerId])) {
            foreach ($this->workerCallMap[$workerId] as $callId => $placeholder) {
                $callResult = new CallResult($callId, NULL, $e);
                $this->onResult($callId, $callResult);
            }
        }
            
        $this->unloadWorkerSession($workerSession);
        $this->spawnWorkerSession();
    }
    
    private function unloadWorkerSession($workerSession) {
        $workerId = spl_object_hash($workerSession);
        
        $subscription = $this->workerSubscriptions[$workerId];
        $subscription->cancel();
        
        unset(
            $this->pendingCallCounts[$workerId],
            $this->workerCallMap[$workerId],
            $this->workerSessions[$workerId],
            $this->workerSubscriptions[$workerId]
        );
    }
    
    private function write(WorkerSession $workerSession, $callFrame = NULL) {
        try {
            return $workerSession->write($callFrame);
        } catch (ResourceException $e) {
            $this->handleBrokenProcessPipe($workerSession, $e);
        }
    }
    
    private function autoTimeout() {
        if (!$this->timeoutSchedule) {
            return;
        }
        
        $now = time();
        
        foreach ($this->timeoutSchedule as $callId => $cutoffTime) {
            if ($now < $cutoffTime) {
                break;
            }
            
            $callResult = new CallResult($callId, $result = NULL, new TimeoutException);
            $this->onResult($callId, $callResult);
        }
    }
    
    function __destruct() {
        if (!$this->isStarted) {
            return;
        }
        
        $this->autoWriteSubscription->cancel();
        
        foreach ($this->workerSessions as $workerSession) {
            $this->unloadWorkerSession($workerSession);
        }
        
        if ($this->autoTimeoutSubscription) {
            $this->autoTimeoutSubscription->cancel();
        }
    }
    
}

