<?php

namespace Amp\Messaging;

use Amp\Reactor;

class ProcessPool {
    
    private $reactor;
    private $workerSessionFactory;
    private $messageFactory;
    
    private $workerSessions;
    private $availableWorkerSessions;
    private $writableWorkerSessions;
    private $workerSessionJobMap;
    private $jobWorkerSessionMap;
    private $timeoutSubscriptions;
    
    private $autoWriteSubscription;
    
    private $workerCmd;
    private $workerCwd;
    private $writeErrorsTo = STDERR;
    private $readTimeout = 60;
    private $autoWriteInterval = 0.025;
    private $isStarted = FALSE;
    
    function __construct(Reactor $reactor, WorkerSessionFactory $wsf = NULL, MessageFactory $mf = NULL) {
        $this->reactor = $reactor;
        $this->workerSessionFactory = $wsf ?: new WorkerSessionFactory;
        $this->messageFactory = $mf ?: new MessageFactory;
        
        $this->workerSessions          = new \SplObjectStorage;
        $this->availableWorkerSessions = new \SplObjectStorage;
        $this->writableWorkerSessions  = new \SplObjectStorage;
        $this->workerSessionJobMap     = new \SplObjectStorage;
        $this->jobWorkerSessionMap     = new \SplObjectStorage;
        $this->timeoutSubscriptions    = new \SplObjectStorage;
    }
    
    function start($workerCmd, $workers = 5, $workerCwd = NULL) {
        if ($this->isStarted) {
            throw new \LogicException(
                'Process pool already started'
            );
        }
        
        $this->workerCmd = $workerCmd;
        $this->workerCwd = $workerCwd;
        
        for ($i=0; $i < $workers; $i++) {
            $this->spawnWorkerSession();
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
    
    function dispatch(MessageJob $job, $timeout = 0) {
        $timeout = filter_var($timeout, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        
        $queueKey = uniqid();
        $this->jobQueue[$queueKey] = $job;
        
        if ($timeout) {
            $timeout = $timeout * $this->reactor->getResolution();
            $subscription = $this->reactor->once($timeout, function() use ($job, $queueKey) {
                $this->expireTimeout($job, $queueKey);
            });
            $this->timeoutSubscriptions->attach($job, $subscription);
        }
        
        $this->checkout();
    }
    
    private function expireTimeout(MessageJob $job, $queueKey) {
        if (isset($this->jobQueue[$queueKey])) {
            unset($this->jobQueue[$queueKey]);
        } else {
            $workerSession = $this->jobWorkerSessionMap->offsetGet($job);
            $this->unloadWorkerSession($workerSession);
        }
        
        $subscription = $this->timeoutSubscriptions->offsetGet($job);
        $subscription->cancel();
        $this->timeoutSubscriptions->detach($job);
        
        try {
            $job->onError(new TimeoutException('Job timeout exceeded'));
        } catch (\Exception $e) {
            fwrite($this->writeErrorsTo, $e);
        }
    }
    
    private function cancelTimeout(MessageJob $job) {
        if ($this->timeoutSubscriptions->contains($job)) {
            $subscription = $this->timeoutSubscriptions->offsetGet($job);
            $subscription->cancel();
            $this->timeoutSubscriptions->detach($job);
        }
    }
    
    private function checkout() {
        if (!count($this->availableWorkerSessions)) {
            return;
        }
        
        $this->availableWorkerSessions->rewind();
        $workerSession = $this->availableWorkerSessions->current();
        $this->availableWorkerSessions->detach($workerSession);
        
        $job = array_shift($this->jobQueue);
        
        $this->workerSessionJobMap->attach($workerSession, $job);
        $this->jobWorkerSessionMap->attach($job, $workerSession);
        
        $payload = $job->getPayload();
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
    
    private function spawnWorkerSession() {
        $workerSession = $this->workerSessionFactory->__invoke($this->workerCmd, $this->workerCwd);
        
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
        
        $frames[] = $frame;
        
        if ($frame->isFin()) {
            $msg = $this->messageFactory->__invoke($frames);
            $job = $this->workerSessionJobMap->offsetGet($workerSession);
            
            $this->cancelTimeout($job);
            
            try {
                $job->onSuccess($msg);
            } catch (\Exception $e) {
                fwrite($this->writeErrorsTo, $e);
            }
            
            $this->checkin($workerSession);
        } else {
            $this->workerSessions->attach($workerSession, [$readSub, $errSub, $frames]);
        }
    }
    
    private function handleUserlandError(WorkerSession $workerSession, \Exception $e) {
        $job = $this->workerSessionJobMap->offsetGet($workerSession);
        $this->cancelTimeout($job);
        
        try {
            $job->onError($e);
        } catch (\Exception $e) {
            fwrite($this->writeErrorsTo, $e);
        }
        
        $this->checkin($workerSession);
    }
    
    private function handleInternalError(WorkerSession $workerSession, \Exception $e) {
        if (!$this->availableWorkerSessions->contains($workerSession)) {
            $job = $this->workerSessionJobMap->offsetGet($workerSession);
            $this->cancelTimeout($job);
            
            try {
                $job->onError($e);
            } catch (\Exception $e) {
                fwrite($this->writeErrorsTo, $e);
            }
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
        
        $job = $this->workerSessionJobMap->offsetGet($workerSession);
        $this->jobWorkerSessionMap->detach($job);
        $this->workerSessionJobMap->detach($workerSession);
        
        $this->availableWorkerSessions->attach($workerSession);
        
        if ($this->jobQueue) {
            $this->checkout();
        }
    }
    
    private function unloadWorkerSession($workerSession) {
        list($readSubscription, $errorSubscription) = $this->workerSessions->offsetGet($workerSession);
        
        $readSubscription->cancel();
        $errorSubscription->cancel();
        
        if ($this->workerSessionJobMap->contains($workerSession)) {
            $job = $this->workerSessionJobMap->offsetGet($workerSession);
            $this->jobWorkerSessionMap->detach($job);
            $this->workerSessionJobMap->detach($workerSession);
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

