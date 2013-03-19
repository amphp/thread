<?php

namespace Amp\Async\Processes;

use Amp\Reactor,
    Amp\Async\Task,
    Amp\Async\CallResult,
    Amp\Async\Dispatchable,
    Amp\Async\IncrementalDispatchable,
    Amp\Async\TimeoutException,
    Amp\Async\Processes\Io\Frame,
    Amp\Async\Processes\Io\Message;

class ProcessDispatcher {
    
    private $reactor;
    private $workerSessionFactory;
    
    private $workerSessions;
    private $availableWorkerSessions;
    private $writableWorkerSessions;
    private $workerSessionCallMap;
    private $callWorkerSessionMap;
    private $timeoutSubscriptions;
    
    private $autoWriteSubscription;
    
    private $workerCmd;
    private $workerCwd;
    private $maxWorkers = 5;
    
    private $responseTimeout = 30;
    private $readTimeout = 60;
    private $autoWriteInterval = 0.025;
    private $writeErrorsTo = STDERR;
    private $isStarted = FALSE;
    
    function __construct(
        Reactor $reactor,
        $workerCmd,
        WorkerSessionFactory $wsf = NULL
    ) {
        $this->reactor = $reactor;
        $this->workerCmd = $workerCmd;
        $this->workerSessionFactory = $wsf ?: new WorkerSessionFactory;
        
        $this->workerSessions          = new \SplObjectStorage;
        $this->availableWorkerSessions = new \SplObjectStorage;
        $this->writableWorkerSessions  = new \SplObjectStorage;
        $this->workerSessionCallMap    = new \SplObjectStorage;
        $this->callWorkerSessionMap    = new \SplObjectStorage;
        $this->timeoutSubscriptions    = new \SplObjectStorage;
    }
    
    function setMaxWorkers($maxWorkers) {
        $this->maxWorkers = (int) $maxWorkers;
    }
    
    function setWorkerCwd($workerCwd) {
        $this->workerCwd = $workerCwd;
    }
    
    function setResponseTimeout($seconds) {
        $this->responseTimeout = filter_var($seconds, FILTER_VALIDATE_INT, ['options' => [
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
                foreach ($this->writableWorkerSessions as $workerSession) {
                    $this->write($workerSession);
                }
            });
        }
        
        $this->isStarted = TRUE;
    }
    
    private function spawnWorkerSession() {
        $workerSession = $this->workerSessionFactory->__invoke($this->workerCmd, $this->workerCwd);
        
        $errSubscription = $this->reactor->onReadable(
            $workerSession->getErrorPipe(),
            function($pipe, $trigger) use ($workerSession) { $this->errorRead($workerSession, $pipe, $trigger); },
            $this->readTimeout
        );
        
        $readSubscription = $this->reactor->onReadable(
            $workerSession->getReadPipe(),
            function($pipe, $trigger) use ($workerSession) { $this->read($workerSession, $trigger); },
            $this->readTimeout
        );
        
        $this->workerSessions->attach($workerSession, [$readSubscription, $errSubscription, $frames = []]);
        $this->availableWorkerSessions->attach($workerSession);
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
        
        if ($this->responseTimeout) {
            $subscription = $this->reactor->once($this->responseTimeout, function() use ($call, $callId) {
                $this->expireTimeout($call, $callId);
            });
            $this->timeoutSubscriptions->attach($call, $subscription);
        }
        
        $this->checkout();
        
        return $callId;
    }
    
    private function expireTimeout(Dispatchable $call, $callId) {
        if (isset($this->callQueue[$callId])) {
            unset($this->callQueue[$callId]);
        } else {
            $workerSession = $this->callWorkerSessionMap->offsetGet($call);
            $this->unloadWorkerSession($workerSession);
        }
        
        $subscription = $this->timeoutSubscriptions->offsetGet($call);
        $subscription->cancel();
        $this->timeoutSubscriptions->detach($call);
        
        $call->onError(new TimeoutException, $callId);
    }
    
    private function checkout() {
        if (!count($this->availableWorkerSessions)) {
            return;
        }
        
        $this->availableWorkerSessions->rewind();
        $workerSession = $this->availableWorkerSessions->current();
        $this->availableWorkerSessions->detach($workerSession);
        
        $callId = key($this->callQueue);
        $call = array_shift($this->callQueue);
        
        $this->workerSessionCallMap->attach($workerSession, [$call, $callId]);
        $this->callWorkerSessionMap->attach($call, $workerSession);
        
        $payload = [$call->getProcedure(), $call->getWorkload()];
        $payload = serialize($payload);
        $length = strlen($payload);
        $frame = new Frame($fin = 1, $rsv = 0, $opcode = Frame::OP_DATA, $payload, $length);
        
        $this->write($workerSession, $frame);
    }
    
    private function errorRead(WorkerSession $workerSession, $errPipe, $triggeredBy) {
        if ($triggeredBy != Reactor::TIMEOUT
            && FALSE === @stream_copy_to_stream($errPipe, $this->writeErrorsTo)
        ) {
            $this->handleInternalError($workerSession, new ResourceException(
                'Worker process STDERR pipe has gone away'
            ));
        }
    }
    
    private function read(WorkerSession $workerSession, $triggeredBy) {
        if ($triggeredBy != Reactor::TIMEOUT) {
            try {
                while ($frame = $workerSession->parse()) {
                    $this->dispatchParsedFrame($workerSession, $frame);
                }
            } catch (ResourceException $e) {
                $this->handleInternalError($workerSession, $e);
            } catch (\RuntimeException $e) {
                $this->handleInternalError($workerSession, $e);
            }
        }
    }
    
    private function dispatchParsedFrame(WorkerSession $workerSession, Frame $frame) {
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
        list($readSub, $errSub, $frames) = $this->workerSessions->offsetGet($workerSession);
        list($call, $callId) = $this->workerSessionCallMap->offsetGet($workerSession);
        
        $isFin = $frame->isFin();
        $isIncremental = ($call instanceof IncrementalDispatchable);
        
        if ($isIncremental && $isFin) {
            $this->cancelTimeout($call);
            $payload = $frame->getPayload();
            $call->onSuccess($payload, $callId);
        } elseif ($isIncremental) {
            $payload = $frame->getPayload();
            $call->onIncrement($payload, $callId);
        } elseif ($isFin) {
            $frames[] = $frame;
            $this->cancelTimeout($call);
            $msg = new Message($frames);
            $result = unserialize($msg->getPayload());
            
            try {
                $call->onSuccess($result, $callId);
                $this->checkin($workerSession);
            } catch (\Exception $e) {
                $this->checkin($workerSession);
                throw $e;
            }
        } else {
            $frames[] = $frame;
            $this->workerSessions->attach($workerSession, [$readSub, $errSub, $frames]);
        }
    }
    
    private function cancelTimeout(Dispatchable $call) {
        if ($this->timeoutSubscriptions->contains($call)) {
            $subscription = $this->timeoutSubscriptions->offsetGet($call);
            $subscription->cancel();
            $this->timeoutSubscriptions->detach($call);
        }
    }
    
    private function handleUserlandError(WorkerSession $workerSession, \Exception $e) {
        list($call, $callId) = $this->workerSessionCallMap->offsetGet($workerSession);
        $this->cancelTimeout($call);
        
        try {
            $call->onError($e, $callId);
            $this->checkin($workerSession);
        } catch (\Exception $e) {
            $this->checkin($workerSession);
            throw $e;
        }
    }
    
    private function handleInternalError(WorkerSession $workerSession, \Exception $e) {
        if (!$this->availableWorkerSessions->contains($workerSession)) {
            list($call, $callId) = $this->workerSessionCallMap->offsetGet($workerSession);
            $this->cancelTimeout($call);
            $call->onError($e, $callId);
        }
        
        $this->unloadWorkerSession($workerSession);
        $this->spawnWorkerSession();
    }
    
    private function write(WorkerSession $workerSession, Frame $frame = NULL) {
        try {
            if ($completedFrame = $workerSession->write($frame)) {
                $this->writableWorkerSessions->detach($workerSession);
            }
        } catch (ResourceException $e) {
            return $this->handleInternalError($workerSession, $e);
        }
    }
    
    private function checkin(WorkerSession $workerSession) {
        list($readSub, $errSub) = $this->workerSessions->offsetGet($workerSession);
        $this->workerSessions->attach($workerSession, [$readSub, $errSub, $frames = []]);
        
        $call = $this->workerSessionCallMap->offsetGet($workerSession)[0];
        $this->callWorkerSessionMap->detach($call);
        $this->workerSessionCallMap->detach($workerSession);
        
        $this->availableWorkerSessions->attach($workerSession);
        
        if ($this->callQueue) {
            $this->checkout();
        }
    }
    
    private function unloadWorkerSession($workerSession) {
        list($readSubscription, $errorSubscription) = $this->workerSessions->offsetGet($workerSession);
        
        $readSubscription->cancel();
        $errorSubscription->cancel();
        
        if ($this->workerSessionCallMap->contains($workerSession)) {
            $call = $this->workerSessionCallMap->offsetGet($workerSession)[0];
            $this->callWorkerSessionMap->detach($call);
            $this->workerSessionCallMap->detach($workerSession);
        }
        
        $this->workerSessions->detach($workerSession);
        $this->writableWorkerSessions->detach($workerSession);
        $this->availableWorkerSessions->detach($workerSession);
    }
    
    function __destruct() {
        $this->stop();
        $this->autoWriteSubscription->cancel();
    }
    
    function stop() {
        $this->autoWriteSubscription->disable();
        
        foreach ($this->workerSessions as $workerSession) {
            $this->unloadWorkerSession($workerSession);
        }
        
        $this->isStarted = FALSE;
    }
    
}

