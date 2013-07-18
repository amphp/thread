<?php

namespace Amp\Dispatch\Process;

use Amp\Reactor;

class Dispatcher {
    
    const CALL = 1;
    const CALL_RESULT = 2;
    const CALL_ERROR = 3;
    const MAX_PROCEDURE_LENGTH = 255;
    const MAX_CALL_ID = 2147483647;
    
    private $reactor;
    private $workerSessionFactory;
    private $autoWriteSubscription;
    private $autoTimeoutSubscription;
    
    private $workerSessions = [];
    private $pendingCallCounts = [];
    private $workerSubscriptions = [];
    private $workerCallMap = [];
    private $callWorkerMap = [];
    private $callFrames = [];
    private $availableWorkers = [];
    private $timeoutSchedule = [];
    private $onResultCallbacks = [];
    private $partialResults = [];
    private $writableWorkers;
    
    private $poolSize;
    private $workerCmd;
    private $workerCwd;
    private $lastCallId = 0;
    private $pendingCalls = 0;
    private $callTimeout = 30;
    private $readTimeout = 60;
    private $autoWriteInterval = 0.025;
    private $timeoutCheckInterval = 1;
    private $writeErrorsTo = STDERR;
    private $isStarted = FALSE;
    private $chrCallCode;
    
    function __construct(Reactor $reactor, $workerCmd, $poolSize = 1, WorkerSessionFactory $wsf = NULL, CallResultFactory $crf = NULL) {
        $this->reactor = $reactor;
        $this->workerCmd = $workerCmd;
        $this->poolSize = (is_int($poolSize) && $poolSize > 0) ? $poolSize : 1;
        $this->workerSessionFactory = $wsf ?: new WorkerSessionFactory;
        $this->callResultFactory = $crf ?: new CallResultFactory;
        
        $this->writableWorkers = new \SplObjectStorage;
        $this->chrCallCode = chr(self::CALL);
        
        if ($this->reactor->isRunning()) {
            $this->start();
        }
        
        $this->reactor->addObserver([
            Reactor::START => [$this, 'start'],
            Reactor::STOP => [$this, 'stop'],
        ]);
    }
    
    function setCallTimeout($seconds) {
        $this->callTimeout = filter_var($seconds, FILTER_VALIDATE_FLOAT, ['options' => [
            'min_range' => 0,
            'default' => 30
        ]]);
    }
    
    function setGranularity($bytes) {
        $this->workerSessionFactory->setGranularity($bytes);
    }
    
    function setWorkerCwd($directory) {
        $this->workerCwd = $directory;
    }
    
    /**
     * Hydrate the worker process pool
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
    function start() {
        if ($this->isStarted) {
            return;
        }
        
        for ($i=0; $i < $this->poolSize; $i++) {
            $this->spawnWorkerSession();
        }
        
        $this->autoWriteSubscription = $this->reactor->schedule(function() {
            foreach ($this->writableWorkers as $workerSession) {
                if ($this->write($workerSession)) {
                    $this->writableWorkers->detach($workerSession);
                }
            }
        }, $this->autoWriteInterval);
        
        if ($this->callTimeout) {
            $this->autoTimeoutSubscription = $this->reactor->schedule(function() {
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
     * Asynchronously execute a procedure and invoke the $onResult callback when complete
     * 
     * @param callable $onResult The callback to process the async execution's CallResult
     * @param string $procedure  The function to execute asynchronously
     * @param string $workload   The data to pass as an argument to the procedure
     * 
     * @return string Returns the task's call ID
     */
    function call(callable $onResult, $procedure, $workload = NULL) {
        if (!$this->isStarted) {
            $this->start();
        }
        
        if (!is_string($procedure)) {
            throw new \InvalidArgumentException;
        } elseif (($procLen = strlen($procedure)) > self::MAX_PROCEDURE_LENGTH) {
            throw new \RangeException(
                'Procedure name exceeds maximum allowable length (' .
                self::MAX_PROCEDURE_LENGTH . '): ' . $procedure
            );
        }
        
        if (($callId = ++$this->lastCallId) == self::MAX_CALL_ID) {
            $this->lastCallId = 0;
        }
        
        $callId = pack('N', $callId);
        
        $this->onResultCallbacks[$callId] = $onResult;
        $this->partialResults[$callId] = '';
        
        if ($this->callTimeout) {
            $this->timeoutSchedule[$callId] = (time() + $this->callTimeout);
        }
        
        $payload = $callId . $this->chrCallCode . chr($procLen) . $procedure . $workload;
        $callFrame = new Frame($fin = 1, $rsv = 0, $opcode = Frame::OP_DATA, $payload);
        
        $this->allocateCall($callId, $callFrame);
        
        return $callId;
    }
    
    private function allocateCall($callId, Frame $callFrame) {
        asort($this->pendingCallCounts);
        $workerId = key($this->pendingCallCounts);
        $workerSession = $this->workerSessions[$workerId];
        
        $this->pendingCallCounts[$workerId]++;
        $this->workerCallMap[$workerId][$callId] = TRUE;
        $this->callWorkerMap[$callId] = $workerId;
        $this->callFrames[$callId] = $callFrame;
        
        if (!$this->write($workerSession, $callFrame)) {
            $this->writableWorkers->attach($workerSession);
        }
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
        
        if ($isFin) {
            $payload = $this->partialResults[$callId] . $payload;
        } else {
            $this->partialResults[$callId] .= $payload;
            return;
        }
        
        if ($callCode == self::CALL_RESULT) {
            $callResult = $this->callResultFactory->make($callId, $payload, $error = NULL);
        } elseif ($callCode == self::CALL_ERROR) {
            $callResult = $this->callResultFactory->make($callId, NULL, new WorkerException($payload));
        } else {
            throw new \UnexpectedValueException(
                'Unexpected data frame result code'
            );
        }
        
        $callback = $this->onResultCallbacks[$callId];
        $callback($callResult);
        
        $workerId = $this->callWorkerMap[$callId];
        
        // The isset check exists to avoid erroneously resetting the call count after a broken
        // process pipe. In this situation the relevent key has already been removed.
        if (isset($this->pendingCallCounts[$workerId])) {
            $this->pendingCallCounts[$workerId]--;
        }
        
        unset(
            $this->timeoutSchedule[$callId],
            $this->onResultCallbacks[$callId],
            $this->partialResults[$callId],
            $this->callWorkerMap[$callId],
            $this->callFrames[$callId],
            $this->workerCallMap[$workerId][$callId]
        );
    }
    
    private function write(WorkerSession $workerSession, $callFrame = NULL) {
        try {
            return $workerSession->write($callFrame);
        } catch (ResourceException $e) {
            $this->handleBrokenProcessPipe($workerSession, $e);
        }
    }
    
    private function handleBrokenProcessPipe(WorkerSession $workerSession, \Exception $e) {
        $workerId = spl_object_hash($workerSession);
        $fatalCallId = key($this->workerCallMap[$workerId]);
        $cancellations = [$fatalCallId => $workerId];
        $this->cancelCalls($cancellations, $e);
    }
    
    private function cancelCalls(array $callIdWorkerIdMap, \Exception $cancellationError) {
        $workerIdsToKill = array_unique($callIdWorkerIdMap);
        $cancelCallIds = array_keys($callIdWorkerIdMap);
        $cancellationCallbacks = [];
        $callsToReallocate = [];
        $workerSessionsToKill = [];
        
        foreach ($workerIdsToKill as $workerId) {
            if ($this->workerCallMap[$workerId]) {
                $workerCalls = array_keys($this->workerCallMap[$workerId]);
                $callsToReallocate = array_merge($callsToReallocate, $workerCalls);
            }
            $workerSessionsToKill[] = $this->workerSessions[$workerId];
        }
        
        $callsToReallocate = array_diff($callsToReallocate, $cancelCallIds);
        
        foreach ($cancelCallIds as $callId) {
            $cancellationCallbacks[$callId] = $this->onResultCallbacks[$callId];
        }
        
        $callFramesForReallocation = [];
        foreach ($callsToReallocate as $callId) {
            $callFramesForReallocation[$callId] = $this->callFrames[$callId];
        }
        
        foreach ($workerSessionsToKill as $workerSession) {
            $this->unloadWorkerSession($workerSession);
            $this->spawnWorkerSession();
        }
        
        foreach ($callFramesForReallocation as $callId => $callFrame) {
            $this->allocateCall($callId, $callFrame);
        }
        
        foreach ($cancellationCallbacks as $callId => $callback) {
            $callResult = $this->callResultFactory->make($callId, $result = NULL, $cancellationError);
            $callback($callResult);
        }
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
    
    private function autoTimeout() {
        if (!$this->timeoutSchedule) {
            return;
        }
        
        $now = time();
        $expirations = [];
        
        foreach ($this->timeoutSchedule as $callId => $cutoffTime) {
            if ($now < $cutoffTime) {
                break;
            } else {
                $expirations[$callId] = $this->callWorkerMap[$callId];
            }
        }
        
        if ($expirations) {
            $this->cancelCalls($expirations, new TimeoutException);
        }
    }
    
    function stop() {
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
    
    function stopReactor() {
        $this->reactor->stop();
    }
    
    function __destruct() {
        $this->stop();
    }
    
}

