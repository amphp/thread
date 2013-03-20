<?php

namespace Amp\Async\Processes;

use Amp\Reactor,
    Amp\Async\Task,
    Amp\Async\CallResult,
    Amp\Async\Dispatchable,
    Amp\Async\IncrementalDispatchable,
    Amp\Async\TimeoutException,
    Amp\Async\Processes\Io\Frame;

class ProcessDispatcher {
    
    private $reactor;
    private $workerSessionFactory;
    
    private $workerSessions;
    private $writableWorkers;
    private $workerCallMap;
    private $callWorkerMap;
    private $inProgressResponses;
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
        
        $this->workerSessions       = new \SplObjectStorage;
        $this->writableWorkers      = new \SplObjectStorage;
        $this->workerCallMap        = new \SplObjectStorage;
        $this->callWorkerMap        = new \SplObjectStorage;
        $this->inProgressResponses  = new \SplObjectStorage;
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
            $this->autoTimeoutSubscription = $this->reactor->repeat($this->autoTimeoutInterval, function() {
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
        $workload = array_slice(func_get_args(), 2);
        
        $onSuccess = function($result) use ($onResult) {
            $callResult = new CallResult($result, $error = NULL);
            $onResult($callResult);
        };
        
        $onError = function($error) use ($onResult) {
            $callResult = new CallResult($result = NULL, $error);
            $onResult($callResult);
        };
        
        $task = new Task($procedure, $workload, $onSuccess, $onError);
        
        return $this->dispatch($task);
    }
    
    function dispatch(Dispatchable $call) {
        $callId = uniqid();
        $this->callQueue[$callId] = $call;
        
        if ($this->autoTimeoutInterval) {
            $this->timeoutSchedule[$callId] = [$call, time() + $this->autoTimeoutInterval];
        }
        
        $this->checkout();
        
        return $callId;
    }
    
    private function autoTimeout() {
        $now = time();
        $timeoutsToClear = [];
        
        foreach ($this->timeoutSchedule as $callId => $callTimeoutArr) {
            list($call, $cutoffTime) = $callTimeoutArr;
            
            if ($now < $cutoffTime) {
                break;
            }
            
            $timeoutsToClear[] = $callId;
            
            if (isset($this->callQueue[$callId])) {
                unset($this->callQueue[$callId]);
            } else {
                $workerSession = $this->callWorkerMap->offsetGet($call);
                $this->unloadWorkerSession($workerSession);
            }
            
            $call->onError(new TimeoutException, $callId);
        }
        
        if ($timeoutsToClear) {
            foreach ($timeoutsToClear as $callId) {
                unset($this->timeoutSchedule[$callId]);
            }
        }
    }
    
    private function checkout() {
        if (!$this->availableWorkers) {
            return;
        }
        
        $workerSession = array_shift($this->availableWorkers);
        $callId = key($this->callQueue);
        $call = $this->callQueue[$callId];
        unset($this->callQueue[$callId]);
        
        $this->workerCallMap->attach($workerSession, [$call, $callId]);
        $this->callWorkerMap->attach($call, $workerSession);
        
        if (!$call instanceof IncrementalDispatchable) {
            $this->inProgressResponses->attach($workerSession, '');
        }
        
        $payload = [$call->getProcedure(), $call->getWorkload()];
        $payload = serialize($payload);
        $length = strlen($payload);
        $frame = new Frame($fin = 1, $rsv = 0, $opcode = Frame::OP_DATA, $payload, $length);
        
        $this->write($workerSession, $frame);
    }
    
    private function read(WorkerSession $workerSession, $triggeredBy) {
        if ($triggeredBy != Reactor::TIMEOUT) {
            try {
                while ($frame = $workerSession->parse()) {
                    $this->receiveParsedFrame($workerSession, $frame);
                }
            } catch (ResourceException $e) {
                $this->handleInternalError($workerSession, $e);
            } catch (\RuntimeException $e) {
                $this->handleInternalError($workerSession, $e);
            }
        }
    }
    
    private function receiveParsedFrame(WorkerSession $workerSession, Frame $frame) {
        $opcode = $frame->getOpcode();
        
        switch($opcode) {
            case Frame::OP_DATA:
                $this->receiveDataFrame($workerSession, $frame);
                break;
            case Frame::OP_CLOSE:
                $this->unloadWorkerSession($workerSession);
                $this->spawnWorkerSession();
                break;
            case Frame::OP_ERROR:
                $this->handleUserlandError($workerSession, new WorkerException(
                    $frame->getPayload()
                ));
                break;
            default:
                throw new \UnexpectedValueException(
                    'Unexpected frame OPCODE: ' . $opcode
                );
        }
    }
    
    private function receiveDataFrame(WorkerSession $workerSession, Frame $frame) {
        list($call, $callId) = $this->workerCallMap->offsetGet($workerSession);
        
        $isFin = $frame->isFin();
        $isIncremental = ($call instanceof IncrementalDispatchable);
        
        if ($isIncremental && $isFin) {
            unset($this->timeoutSchedule[$callId]);
            $payload = $frame->getPayload();
            $call->onSuccess($payload, $callId);
        } elseif ($isIncremental) {
            $payload = $frame->getPayload();
            $call->onIncrement($payload, $callId);
        } elseif ($isFin) {
            $result = $this->inProgressResponses->offsetGet($workerSession) . $frame->getPayload();
            $result = unserialize($result);
            unset($this->timeoutSchedule[$callId]);
            
            try {
                $call->onSuccess($result, $callId);
                $this->checkin($workerSession);
            } catch (\Exception $e) {
                $this->checkin($workerSession);
                throw $e;
            }
        } else {
            $result = $this->inProgressResponses->offsetGet($workerSession) . $frame->getPayload();
            $this->inProgressResponses->attach($workerSession, $result);
        }
    }
    
    private function handleUserlandError(WorkerSession $workerSession, \Exception $e) {
        list($call, $callId) = $this->workerCallMap->offsetGet($workerSession);
        unset($this->timeoutSchedule[$callId]);
        
        try {
            $call->onError($e, $callId);
            $this->checkin($workerSession);
        } catch (\Exception $e) {
            $this->checkin($workerSession);
            throw $e;
        }
    }
    
    private function handleInternalError(WorkerSession $workerSession, \Exception $e) {
        if ($this->workerCallMap->contains($workerSession)) {
            list($call, $callId) = $this->workerCallMap->offsetGet($workerSession);
            unset($this->timeoutSchedule[$callId]);
            $call->onError($e, $callId);
        }
        
        $this->unloadWorkerSession($workerSession);
        $this->spawnWorkerSession();
    }
    
    private function write(WorkerSession $workerSession, Frame $frame = NULL) {
        try {
            if ($completedFrame = $workerSession->write($frame)) {
                $this->writableWorkers->detach($workerSession);
            }
        } catch (ResourceException $e) {
            return $this->handleInternalError($workerSession, $e);
        }
    }
    
    private function checkin(WorkerSession $workerSession) {
        $call = $this->workerCallMap->offsetGet($workerSession)[0];
        $this->callWorkerMap->detach($call);
        $this->workerCallMap->detach($workerSession);
        $this->inProgressResponses->detach($workerSession);
        
        $this->availableWorkers[] = $workerSession;
        
        if ($this->callQueue) {
            $this->checkout();
        }
    }
    
    private function unloadWorkerSession($workerSession) {
        $this->workerSessions->offsetGet($workerSession)->cancel();
        
        if ($this->workerCallMap->contains($workerSession)) {
            list($call, $callId) = $this->workerCallMap->offsetGet($workerSession);
            
            $this->callWorkerMap->detach($call);
            $this->workerCallMap->detach($workerSession);
            $this->inProgressResponses->detach($workerSession);
            $this->writableWorkers->detach($workerSession);
            
            unset($this->timeoutSchedule[$callId]);
            
        } else {
            $availabilityKey = array_search($workerSession, $this->availableWorkers);
            unset($this->availableWorkers[$availabilityKey]);
        }
        
        $this->workerSessions->detach($workerSession);
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

