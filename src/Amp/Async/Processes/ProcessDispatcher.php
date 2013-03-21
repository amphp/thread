<?php

namespace Amp\Async\Processes;

use Amp\Reactor,
    Amp\Async\Task,
    Amp\Async\CallResult,
    Amp\Async\Dispatchable,
    Amp\Async\IncrementalDispatchable,
    Amp\Async\TimeoutException;

class ProcessDispatcher {
    
    private $reactor;
    private $workerSessionFactory;
    
    private $workerSessions;
    private $writableWorkers;
    private $workerCallMap;
    private $callWorkerMap = [];
    private $availableWorkers = [];
    private $timeoutSchedule = [];
    
    private $autoWriteSubscription;
    private $autoTimeoutSubscription;
    
    private $workerCmd;
    private $workerCwd;
    private $maxWorkers = 5;
    
    private $readTimeout = 60;
    private $autoWriteInterval = 0.025;
    private $autoTimeoutInterval = 30;
    private $writeErrorsTo = STDERR;
    private $isStarted = FALSE;
    
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
    
    function setAutoTimeoutInterval($seconds) {
        $this->autoTimeoutInterval = filter_var($seconds, FILTER_VALIDATE_INT, ['options' => [
            'min_range' => 0,
            'default' => 30
        ]]);
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
                    $this->write($workerSession);
                }
            });
        }
        
        if ($this->autoTimeoutSubscription) {
            $this->autoTimeoutSubscription->enable();
        } else {
            $this->autoTimeoutSubscription = $this->reactor->repeat(1, function() {
                if ($this->timeoutSchedule) {
                    $this->autoTimeout();
                }
            });
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
        
        $this->workerSessions->attach($workerSession, $readSubscription);
        $this->availableWorkers[] = $workerSession;
    }
    
    function call(callable $onResult, $procedure, $varArgs = NULL) {
        $callId = uniqid();
        $workload = array_slice(func_get_args(), 2);
        $this->callQueue[$callId] = [$onResult, $procedure, $workload, $result = NULL];
        
        if ($this->autoTimeoutInterval) {
            $this->timeoutSchedule[$callId] = (time() + $this->autoTimeoutInterval);
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
        unset($this->callQueue[$callId]);
        
        $this->workerCallMap->attach($workerSession, [$callArr, $callId]);
        $this->callWorkerMap[$callId] = $workerSession;
        
        $procedure = $callArr[1];
        $workload  = $callArr[2];
        $payload   = [$procedure, $workload];
        $payload   = serialize($payload);
        $frame     = new Frame($fin = 1, $rsv = 0, $opcode = Frame::OP_DATA, $payload);
        
        $this->write($workerSession, $frame);
    }
    
    private function read(WorkerSession $workerSession, $triggeredBy) {
        if ($triggeredBy != Reactor::TIMEOUT) {
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
            case Frame::OP_ERROR:
                $this->handleUserlandError($workerSession, new WorkerException($payload));
                break;
            default:
                throw new \UnexpectedValueException(
                    'Unexpected frame OPCODE: ' . $opcode
                );
        }
    }
    
    private function receiveDataFrame(WorkerSession $workerSession, array $frameArr) {
        list($callArr, $callId) = $this->workerCallMap->offsetGet($workerSession);
        list($isFin, $rsv, $opcode, $payload) = $frameArr;
        list($onResult, $procedure, $workload, $result) = $callArr;
        
        if ($isFin) {
            $callResult = new CallResult(unserialize($result . $payload));
            try {
                $onResult($callResult, $callId);
                $this->checkin($workerSession);
            } catch (\Exception $e) {
                $this->checkin($workerSession);
                throw $e;
            }
        } else {
            $result = $result . $payload;
            $callArr = [$onResult, $procedure, $workload, $result];
            $this->workerCallMap->attach($workerSession, [$callArr, $callId]);
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
            if ($allFramesWritten = $workerSession->write($frame)) {
                $this->writableWorkers->detach($workerSession);
            }
        } catch (ResourceException $e) {
            return $this->handleInternalError($workerSession, $e);
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
                unset($this->callQueue[$callId]);
            }
            
            $onResult(new TimeoutException, $callId);
        }
    }
    
    function __destruct() {
        $this->stop();
        $this->autoWriteSubscription->cancel();
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
    
}

