<?php

namespace Amp\Async;

use Amp\Reactor;

class Dispatcher {
    
    const MAX_CALL_ID = 2147483647;
    const CALL        = 1;
    const CALL_RESULT = 3;
    const CALL_ERROR  = 7;
    
    private $reactor;
    private $workerSessionFactory;
    
    private $workerSessions;
    private $writableWorkers;
    private $workerCallMap;
    private $callWorkerMap = [];
    private $availableWorkers = [];
    private $timeoutSchedule = [];

    /**
     * @var \Amp\Subscription
     */
    private $autoWriteSubscription;

    /**
     * @var \Amp\Subscription
     */
    private $autoTimeoutSubscription;

    private $callQueue = [];
    
    private $workerCmd;
    private $workerCwd;
    private $maxWorkers = 5;
    
    private $callTimeout = 30;
    private $readTimeout = 60;
    private $autoWriteInterval = 0.025;
    private $autoTimeoutInterval = 1;
    private $serializeWorkload = TRUE;
    private $writeErrorsTo = STDERR;
    private $isStarted = FALSE;
    private $lastCallId = 0;
    
    function __construct(Reactor $reactor, $workerCmd, WorkerSessionFactory $wsf = NULL) {
        $this->reactor = $reactor;
        $this->workerCmd = $workerCmd;
        $this->workerSessionFactory = $wsf ?: new WorkerSessionFactory;
        
        $this->workerSessions  = new \SplObjectStorage;
        $this->writableWorkers = new \SplObjectStorage;
        $this->workerCallMap   = new \SplObjectStorage;
    }
    
    function setMaxWorkers($maxWorkers) {
        $this->maxWorkers = (int) $maxWorkers;
    }
    
    function setWorkerCwd($workerCwd) {
        $this->workerCwd = $workerCwd;
    }
    
    function setCallTimeout($seconds) {
        $this->callTimeout = filter_var($seconds, FILTER_VALIDATE_INT, ['options' => [
            'min_range' => 0,
            'default' => 30
        ]]);
    }
    
    function serializeWorkload($boolFlag) {
        $this->serializeWorkload = filter_var($boolFlag, FILTER_VALIDATE_BOOLEAN);
    }
    
    function setGranularity($bytes) {
        $this->workerSessionFactory->setGranularity($bytes);
    }
    
    function start() {
        if ($this->isStarted) {
            return;
        }
        
        for ($i=0; $i < $this->maxWorkers; $i++) {
            $this->spawnWorkerSession();
        }
        
        if ($this->autoWriteSubscription) {
            $this->autoWriteSubscription->enable();
        } else {
            $this->autoWriteSubscription = $this->reactor->repeat($this->autoWriteInterval, function() {
                foreach ($this->writableWorkers as $workerSession) {
                    if ($this->write($workerSession)) {
                        $this->writableWorkers->detach($workerSession);
                    }
                }
            });
        }
        
        if ($this->callTimeout && $this->autoTimeoutSubscription) {
            $this->autoTimeoutSubscription->enable();
        } elseif ($this->callTimeout) {
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
        
        $this->workerSessions->attach($workerSession, $readSubscription);
        $this->availableWorkers[] = $workerSession;
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
        
        $procLen  = chr(strlen($procedure));
        $workload = $this->serializeWorkload ? serialize(array_slice(func_get_args(), 2)) : $varArgs;
        $payload  = pack('N', $callId) . $procLen . $procedure . $workload;
        
        $this->callQueue[$callId] = [$onResult, $payload, $result = NULL];
        
        if ($this->callTimeout) {
            $this->timeoutSchedule[$callId] = (time() + $this->callTimeout);
        }
        
        if ($this->availableWorkers) {
            $this->checkout();
        }
        
        return $callId;
    }
    
    private function checkout() {
        $workerSession = array_shift($this->availableWorkers);
        $callId = key($this->callQueue);
        $callArr = $this->callQueue[$callId];
        $rawRequest = $callArr[1];
        
        unset($this->callQueue[$callId]);
        
        $this->workerCallMap->attach($workerSession, [$callArr, $callId]);
        $this->callWorkerMap[$callId] = $workerSession;
        
        $frame = new Frame($fin = 1, $rsv = 0b001, $opcode = Frame::OP_DATA, $rawRequest);
        
        if (!$this->write($workerSession, $frame)) {
            $this->writableWorkers->attach($workerSession);
        }
    }
    
    private function read(WorkerSession $workerSession, $triggeredBy) {
        if ($triggeredBy !== Reactor::TIMEOUT) {
            try {
                if ($frameArr = $workerSession->parse()) {
                    $this->receiveParsedFrame($workerSession, $frameArr);
                }
            } catch (ResourceException $e) {
                $this->handleInternalError($workerSession, $e);
            } catch (\RuntimeException $e) {
                $this->handleInternalError($workerSession, $e);
            }
        }
    }
    
    private function receiveParsedFrame(WorkerSession $workerSession, array $frameArr) {
        list($isFin, $rsv, $opcode, $payload) = $frameArr;
        
        switch($opcode) {
            case Frame::OP_DATA:
                $this->receiveDataFrame($workerSession, $frameArr);
                break;
            case Frame::OP_CLOSE:
                $this->unloadWorkerSession($workerSession);
                $this->spawnWorkerSession();
                break;
            default:
                /**
                 * @TODO Figure out a way to make this problem easier to debug
                 */
                throw new \UnexpectedValueException(
                    'Unexpected frame OPCODE: ' . $opcode
                );
        }
    }
    
    private function receiveDataFrame(WorkerSession $workerSession, array $frameArr) {
        list($callArr, $callId) = $this->workerCallMap->offsetGet($workerSession);
        list($isFin, $rsv, $opcode, $payload) = $frameArr;
        list($onResult, $rawRequest, $result) = $callArr;
        
        $result = $result . $payload;
        
        if (!$isFin) {
            $callArr = [$onResult, $procedure, $workload, $result];
            $this->workerCallMap->attach($workerSession, [$callArr, $callId]);
            return;
        }
        
        $callId = current(unpack('N', substr($result, 0, 4)));
        $result = substr($result, 4);
        $result = $this->serializeWorkload ? unserialize($result) : $result;
        
        if ($rsv & self::CALL_RESULT) {
            $callResult = new CallResult($result, $error = NULL);
        } elseif ($rsv & self::CALL_ERROR) {
            $callResult = new CallResult(NULL, new WorkerException($result));
        } else {
            // @TODO handle invalid RSV bits
        }
        
        try {
            $onResult($callResult, $callId);
            $this->checkin($workerSession);
        } catch (\Exception $e) {
            $this->checkin($workerSession);
            throw $e;
        }
    }
    
    private function handleUserlandError(WorkerSession $workerSession, \Exception $e) {
        list($callArr, $callId) = $this->workerCallMap->offsetGet($workerSession);
        
        unset($this->timeoutSchedule[$callId]);
        
        $onResult = $callArr[0];
        $callResult = new CallResult(NULL, $e);
        
        try {
            $onResult($callResult, $callId);
            $this->checkin($workerSession);
        } catch (\Exception $e) {
            $this->checkin($workerSession);
            throw $e;
        }
    }
    
    private function handleInternalError(WorkerSession $workerSession, \Exception $e) {
        $this->unloadWorkerSession($workerSession);
        
        if ($this->workerCallMap->contains($workerSession)) {
            list($callArr, $callId) = $this->workerCallMap->offsetGet($workerSession);
            unset($this->timeoutSchedule[$callId]);
            
            $onResult = $callArr[0];
            $callResult = new CallResult(NULL, $e);
            $onResult($callResult, $callId);
        }
        
        $this->spawnWorkerSession();
    }
    
    private function write(WorkerSession $workerSession, Frame $frame = NULL) {
        try {
            return $workerSession->write($frame);
        } catch (ResourceException $e) {
            $this->handleInternalError($workerSession, $e);
        }
    }
    
    private function checkin(WorkerSession $workerSession) {
        list($callArr, $callId) = $this->workerCallMap->offsetGet($workerSession);
        
        unset(
            $this->timeoutSchedule[$callId],
            $this->callWorkerMap[$callId]
        );
            
        $this->workerCallMap->detach($workerSession);
        $this->availableWorkers[] = $workerSession;
        
        if ($this->callQueue) {
            $this->checkout();
        }
    }
    
    private function unloadWorkerSession($workerSession) {
        $this->workerSessions->offsetGet($workerSession)->cancel();
        
        if ($this->workerCallMap->contains($workerSession)) {
            list($call, $callId) = $this->workerCallMap->offsetGet($workerSession);
            unset(
                $this->timeoutSchedule[$callId],
                $this->callWorkerMap[$callId]
            );
            
            $this->workerCallMap->detach($workerSession);
            $this->writableWorkers->detach($workerSession);
            
        } else {
            $availabilityKey = array_search($workerSession, $this->availableWorkers);
            unset($this->availableWorkers[$availabilityKey]);
        }
        
        $this->workerSessions->detach($workerSession);
    }
    
    private function autoTimeout() {
        $now = time();
        
        foreach ($this->timeoutSchedule as $callId => $cutoffTime) {
            if ($now < $cutoffTime) {
                break;
            }
            
            if (isset($this->callWorkerMap[$callId])) {
                $workerSession = $this->callWorkerMap[$callId];
                $onResult = $this->workerCallMap->offsetGet($workerSession)[0][0];
                $this->unloadWorkerSession($workerSession);
            } else {
                $onResult = $this->callQueue[$callId][0];
                unset($this->callQueue[$callId]);
            }
            
            $callResult = new CallResult($result = NULL, new TimeoutException);
            $onResult($callResult, $callId);
        }
    }
    
    function stop() {
        if ($this->isStarted) {
            $this->autoWriteSubscription->disable();
            
            foreach ($this->workerSessions as $workerSession) {
                $this->unloadWorkerSession($workerSession);
            }
        }
        
        $this->isStarted = FALSE;
    }
    
    function __destruct() {
        $this->stop();
        $this->autoWriteSubscription->cancel();
    }
    
}

