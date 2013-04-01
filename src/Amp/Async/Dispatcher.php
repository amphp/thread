<?php

namespace Amp\Async;

use Amp\Reactor;

class Dispatcher {
    
    const MAX_CALL_ID = 2147483647;
    
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
    private $pendingCalls = 0;
    private $callTimeout = 30;
    private $readTimeout = 60;
    private $autoWriteInterval = 0.025;
    private $autoTimeoutInterval = 1;
    private $serializeWorkload = TRUE;
    private $writeErrorsTo = STDERR;
    private $notifyOnPartialResult = FALSE;
    private $isStarted = FALSE;
    
    function __construct(Reactor $reactor, WorkerSessionFactory $wsf = NULL) {
        $this->reactor = $reactor;
        $this->workerSessionFactory = $wsf ?: new WorkerSessionFactory;
        $this->writableWorkers = new \SplObjectStorage;
    }
    
    function setCallTimeout($seconds) {
        $this->callTimeout = filter_var($seconds, FILTER_VALIDATE_INT, ['options' => [
            'min_range' => 0,
            'default' => 30
        ]]);
    }
    
    function setGranularity($bytes) {
        $this->workerSessionFactory->setGranularity($bytes);
    }
    
    function serializeWorkload($boolFlag) {
        $this->serializeWorkload = filter_var($boolFlag, FILTER_VALIDATE_BOOLEAN);
    }
    
    function notifyOnPartialResult($boolFlag) {
        $this->notifyOnPartialResult = filter_var($boolFlag, FILTER_VALIDATE_BOOLEAN);
    }
    
    function start($poolSize, $cmd, $cwd = NULL) {
        if ($this->isStarted) {
            return;
        }
        
        $this->workerCmd = $cmd;
        $this->workerCwd = $cwd;
        
        for ($i=0; $i < $poolSize; $i++) {
            $this->spawnWorkerSession();
        }
        
        $this->autoWriteSubscription = $this->reactor->repeat($this->autoWriteInterval, function() {
            foreach ($this->writableWorkers as $workerSession) {
                if ($this->write($workerSession)) {
                    $this->writableWorkers->detach($workerSession);
                }
            }
        });
        
        if ($this->callTimeout) {
            $this->autoTimeoutSubscription = $this->reactor->repeat(1, function() {
                if ($this->timeoutSchedule) {
                    $this->autoTimeout();
                }
            });
        }
        
        return $this->isStarted = TRUE;
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
     * @param string $procedureName The function to execute asynchronously
     * @param mixed $varArgs, ...
     * @return string Returns the task's call ID
     */
    function call(callable $onResult, $procedure, $varArgs = NULL) {
        if (($callId = ++$this->lastCallId) == self::MAX_CALL_ID) {
            $this->lastCallId = 0;
        }
        
        $callId = pack('N', $callId);
        
        $this->onResultCallbacks[$callId] = $onResult;
        $this->partialResults[$callId] = NULL;
        
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
        
        $procLen = chr(strlen($procedure));
        $workload = $this->serializeWorkload ? serialize(array_slice(func_get_args(), 2)) : $varArgs;
        $payload = $callId . $procLen . $procedure . $workload;
        $callFrame = new Frame($fin = 1, $rsv = 1, $opcode = Frame::OP_DATA, $payload);
        
        if (!$this->write($workerSession, $callFrame)) {
            $this->writableWorkers->attach($workerSession);
        }
        
        return $callId;
    }
    
    private function read(WorkerSession $workerSession, $triggeredBy) {
        if ($triggeredBy === Reactor::TIMEOUT) {
            return;
        }
        
        try {
            if ($frameArr = $workerSession->parse()) {
                $this->receiveParsedFrame($workerSession, $frameArr);
            }
        } catch (ResourceException $e) {
            $this->handleBrokenProcessPipe($workerSession, $e);
        }
    }
    
    private function receiveParsedFrame(WorkerSession $workerSession, array $frameArr) {
        $opcode = $frameArr[2];
        
        switch($opcode) {
            case Frame::OP_DATA:
                $this->receiveDataFrame($frameArr);
                break;
            case Frame::OP_CLOSE:
                $this->unloadWorkerSession($workerSession);
                $this->spawnWorkerSession();
                break;
            default:
                throw new \UnexpectedValueException(
                    'Unexpected frame OPCODE: ' . $opcode
                );
        }
    }
    
    private function receiveDataFrame(array $frameArr) {
        list($isFin, $rsv, $opcode, $payload) = $frameArr;
        
        $callId  = substr($payload, 0, 4);
        $payload = substr($payload, 4);
        
        if (!$isFin && $this->notifyOnPartialResult) {
            // No RSV check is made here because error results should always set the FIN bit
            $callResult = new CallResult($callId, $payload, $error = NULL, $isFin);
            $callback = $this->onResultCallbacks[$callId];
            $callback($callResult);
            return;
        } elseif (!$isFin) {
            $this->partialResults[$callId] .= $payload;
            return;
        } else {
            $payload = $this->partialResults[$callId] . $payload;
        }
        
        $payload = $this->serializeWorkload ? unserialize($payload) : $payload;
        
        if ($rsv & self::CALL_RESULT) {
            $callResult = new CallResult($callId, $payload, $error = NULL, $isFin);
        } elseif ($rsv & self::CALL_ERROR) {
            $callResult = new CallResult($callId, $result = NULL, new WorkerException($payload), $isFin);
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
        
        foreach ($this->workerCallMap[$workerId] as $callId => $placeholder) {
            $callResult = new CallResult(NULL, $e);
            $this->onResult($callId, $callResult);
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
        $now = time();
        
        foreach ($this->timeoutSchedule as $callId => $cutoffTime) {
            if ($now < $cutoffTime) {
                break;
            }
            
            $callResult = new CallResult($result = NULL, new TimeoutException);
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

