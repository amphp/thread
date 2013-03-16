<?php

namespace Amp\Messaging;

use Amp\Reactor\Reactor;

class ProcessManager {
    
    private $reactor;
    private $workerSessionFactory;
    private $messageFactory;
    
    private $workerSessions;
    private $availableWorkerSessions;
    private $writableWorkerSessions;
    private $workerSessionCallMap;
    private $callWorkerSessionMap;
    private $timeoutSubscriptions;
    
    private $autoWriteSubscription;
    
    private $runEnvironment;
    private $writeErrorsTo = STDERR;
    private $isStarted = FALSE;
    private $readTimeout = 60;
    private $autoWriteInterval = 0.25;
    
    function __construct(Reactor $reactor, WorkerSessionFactory $wsf = NULL, MessageFactory $mf = NULL) {
        $this->reactor = $reactor;
        $this->workerSessionFactory = $wsf ?: new WorkerSessionFactory;
        $this->messageFactory = $mf ?: new MessageFactory;
        
        $this->workerSessions          = new \SplObjectStorage;
        $this->availableWorkerSessions = new \SplObjectStorage;
        $this->writableWorkerSessions  = new \SplObjectStorage;
        $this->workerSessionCallMap    = new \SplObjectStorage;
        $this->callWorkerSessionMap    = new \SplObjectStorage;
        $this->timeoutSubscriptions    = new \SplObjectStorage;
    }
    
    function start($cmd, $workers = 5, $cwd = NULL) {
        if ($this->isStarted) {
            throw new \LogicException(
                'Process manager already running'
            );
        }
        
        $this->runEnvironment = [$cmd, $cwd];
        
        for ($i=0; $i < $workers; $i++) {
            $this->spawnWorkerSession($cmd, $cwd);
        }
        
        if ($this->autoWriteSubscription) {
            $this->autoWriteSubscription->enable();
        } else {
            $autoWriteInterval = $this->autoWriteInterval * $this->reactor->getResolution();
            $this->autoWriteSubscription = $this->reactor->repeat($autoWriteInterval, [$this, 'autoWrite']);
        }
        
        $this->isStarted = TRUE;
    }
    
    function autoWrite() {
        foreach ($this->writableWorkerSessions as $workerSession) {
            $this->write($workerSession);
        }
    }
    
    function dispatch(Call $call, $timeout = 0) {
        $timeout = filter_var($timeout, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        
        $queueKey = uniqid();
        $this->callQueue[$queueKey] = $call;
        
        if ($timeout) {
            $timeout = $timeout * $this->reactor->getResolution();
            $subscription = $this->reactor->once($timeout, function() use ($call, $queueKey) {
                $this->expireTimeout($call, $queueKey);
            });
            $this->timeoutSubscriptions->attach($call, $subscription);
        }
        
        $this->checkout();
    }
    
    private function expireTimeout(Call $call, $queueKey) {
        if (isset($this->callQueue[$queueKey])) {
            unset($this->callQueue[$queueKey]);
        } else {
            $workerSession = $this->callWorkerSessionMap->offsetGet($call);
            $this->unloadWorkerSession($workerSession);
        }
        
        $subscription = $this->timeoutSubscriptions->offsetGet($call);
        $subscription->cancel();
        $this->timeoutSubscriptions->detach($call);
        
        try {
            $call->onError(new TimeoutException('Call timeout exceeded'));
        } catch (\Exception $e) {
            fwrite($this->writeErrorsTo, $e);
        }
    }
    
    private function cancelTimeout(Call $call) {
        if ($this->timeoutSubscriptions->contains($call)) {
            $subscription = $this->timeoutSubscriptions->offsetGet($call);
            $subscription->cancel();
            $this->timeoutSubscriptions->detach($call);
        }
    }
    
    private function checkout() {
        if (!count($this->availableWorkerSessions)) {
            return;
        }
        
        $this->availableWorkerSessions->rewind();
        $workerSession = $this->availableWorkerSessions->current();
        $this->availableWorkerSessions->detach($workerSession);
        
        $call = array_shift($this->callQueue);
        
        $this->workerSessionCallMap->attach($workerSession, $call);
        $this->callWorkerSessionMap->attach($call, $workerSession);
        
        $payload = $call->getPayload();
        $length = strlen($payload);
        $frame = new Frame($fin = 1, $rsv = 0, $opcode = Frame::OP_DATA, $payload, $length);
        
        $this->write($workerSession, $frame);
    }
    
    function stop() {
        $this->autoWriteSubscription->disable();
        
        foreach ($this->workerSessions as $workerSession) {
            $this->unloadWorkerSession($workerSession);
        }
        
        $this->isStarted = FALSE;
    }
    
    private function spawnWorkerSession($cmd, $cwd) {
        $workerSession = $this->workerSessionFactory->__invoke($cmd, $cwd);
        
        $readTimeout = $this->readTimeout * $this->reactor->getResolution();
        
        $errSubscription = $this->reactor->onReadable(
            $workerSession->getErrorPipe(),
            function($pipe, $trigger) use ($workerSession) { $this->errorRead($workerSession, $pipe, $trigger); },
            $readTimeout
        );
        
        $readSubscription = $this->reactor->onReadable(
            $workerSession->getReadPipe(),
            function($pipe, $trigger) use ($workerSession) { $this->read($workerSession, $trigger); },
            $readTimeout
        );
        
        $this->workerSessions->attach($workerSession, [$readSubscription, $errSubscription, $frames = []]);
        $this->availableWorkerSessions->attach($workerSession);
    }
    
    private function respawn() {
        list($cmd, $cwd) = $this->runEnvironment;
        $this->spawnWorkerSession($cmd, $cwd);
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
        if ($triggeredBy == Reactor::TIMEOUT) {
            return;
        }
        
        try {
            if (!$frame = $workerSession->parse()) {
                return;
            }
        } catch (ResourceException $e) {
            return $this->handleInternalError($workerSession, $e);
        } catch (\RuntimeException $e) {
            return $this->handleInternalError($workerSession, $e);
        }    
        
        $opcode = $frame->getOpcode();
        
        switch($opcode) {
            case Frame::OP_DATA:
                $this->receiveDataFrame($workerSession, $frame);
                break;
            case Frame::OP_CLOSE:
                $this->unloadWorkerSession($workerSession);
                $this->respawn();
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
        
        $frames[] = $frame;
        
        if ($frame->isFin()) {
            $msg = $this->messageFactory->__invoke($frames);
            $call = $this->workerSessionCallMap->offsetGet($workerSession);
            
            $this->cancelTimeout($call);
            
            try {
                $call->onSuccess($msg);
            } catch (\Exception $e) {
                fwrite($this->writeErrorsTo, $e);
            }
            
            $this->checkin($workerSession);
        } else {
            $this->workerSessions->attach($workerSession, [$readSub, $errSub, $frames]);
        }
    }
    
    private function handleUserlandError(WorkerSession $workerSession, \Exception $e) {
        $call = $this->workerSessionCallMap->offsetGet($workerSession);
        $this->cancelTimeout($call);
        
        try {
            $call->onError($e);
        } catch (\Exception $e) {
            fwrite($this->writeErrorsTo, $e);
        }
        
        $this->checkin($workerSession);
    }
    
    private function handleInternalError(WorkerSession $workerSession, \Exception $e) {
        if (!$this->availableWorkerSessions->contains($workerSession)) {
            $call = $this->workerSessionCallMap->offsetGet($workerSession);
            $this->cancelTimeout($call);
            
            try {
                $call->onError($e);
            } catch (\Exception $e) {
                fwrite($this->writeErrorsTo, $e);
            }
        }
        
        $this->unloadWorkerSession($workerSession);
        $this->respawn();
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
        
        $call = $this->workerSessionCallMap->offsetGet($workerSession);
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
            $call = $this->workerSessionCallMap->offsetGet($workerSession);
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
    
}

