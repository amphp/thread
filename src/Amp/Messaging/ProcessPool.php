<?php

namespace Amp\Messaging;

use Amp\Reactor;

/*
// AMP - Async Messaging Platform
// AMP - Aerys Messaging Protocol
// AMP - Async My PHP
// AMP - AMP Messaging Protocol (recursive)

interface Amp\Async\CallDispatcher {
    function start();
    function call(Call $call); // Returns the call ID referenced by onSuccess and onError callbacks
    function stop();
}

interface Amp\Async\Call {
    function getProcedure();
    function getWorkload();
    function onSuccess($callId, Message $msg);
    function onError($callId, \Exception $e);
}

interface Amp\Async\PartialCall extends Call {
    function onPartial($callId, $data);
}

class Amp\Async\ProcPoolDispatcher implements CallDispatcher {}
class Amp\Async\GearmanDispatcher implements CallDispatcher {}

*/


class ProcessPool {
    
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
    
    private $workerCmd;
    private $workerCwd;
    private $maxWorkers = 5;
    
    private $callTimeout = 10;
    private $readTimeout = 60;
    private $autoWriteInterval = 0.025;
    private $writeErrorsTo = STDERR;
    private $isStarted = FALSE;
    
    function __construct(
        Reactor $reactor,
        $workerCmd,
        WorkerSessionFactory $wsf = NULL,
        MessageFactory $mf = NULL
    ) {
        $this->reactor = $reactor;
        $this->workerCmd = $workerCmd;
        $this->workerSessionFactory = $wsf ?: new WorkerSessionFactory;
        $this->messageFactory = $mf ?: new MessageFactory;
        
        $this->workerSessions          = new \SplObjectStorage;
        $this->availableWorkerSessions = new \SplObjectStorage;
        $this->writableWorkerSessions  = new \SplObjectStorage;
        $this->workerSessionCallMap    = new \SplObjectStorage;
        $this->callWorkerSessionMap    = new \SplObjectStorage;
        $this->timeoutSubscriptions    = new \SplObjectStorage;
        
        $this->callTimeout = $this->callTimeout * $this->reactor->getResolution();
        $this->readTimeout = $this->readTimeout * $this->reactor->getResolution();
    }
    
    function setMaxWorkers($maxWorkers) {
        $this->maxWorkers = (int) $maxWorkers;
    }
    
    function setWorkerCwd($workerCwd) {
        $this->workerCwd = $workerCwd;
    }
    
    function setCallTimeout($seconds) {
        $seconds = filter_var($seconds, FILTER_VALIDATE_INT, ['options' => [
            'min_range' => 0,
            'default' => 10
        ]]);
        
        $this->callTimeout = $seconds * $this->reactor->getResolution();
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
            $autoWriteInterval = $this->autoWriteInterval * $this->reactor->getResolution();
            $this->autoWriteSubscription = $this->reactor->repeat($autoWriteInterval, function() {
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
    
    function call(Call $call) {
        $callId = uniqid();
        $this->callQueue[$callId] = $call;
        
        if ($this->callTimeout) {
            $subscription = $this->reactor->once($this->callTimeout, function() use ($call, $callId) {
                $this->expireTimeout($call, $callId);
            });
            $this->timeoutSubscriptions->attach($call, $subscription);
        }
        
        $this->checkout();
        
        return $callId;
    }
    
    private function expireTimeout(Call $call, $callId) {
        if (isset($this->callQueue[$callId])) {
            unset($this->callQueue[$callId]);
        } else {
            $workerSession = $this->callWorkerSessionMap->offsetGet($call);
            $this->unloadWorkerSession($workerSession);
        }
        
        $subscription = $this->timeoutSubscriptions->offsetGet($call);
        $subscription->cancel();
        $this->timeoutSubscriptions->detach($call);
        
        $call->onError($callId, new TimeoutException(
            'Job timeout exceeded'
        ));
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
        
        $payload = $call->getPayload();
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
        
        $frames[] = $frame;
        
        if ($frame->isFin()) {
            $this->cancelTimeout($call);
            $msg = $this->messageFactory->__invoke($frames);
            $call->onSuccess($callId, $msg);
            $this->checkin($workerSession);
        } elseif ($call instanceof OnFrameCall) {
            $call->onFrame($callId, $frame);
            $this->workerSessions->attach($workerSession, [$readSub, $errSub, $frames]);
        } else {
            $this->workerSessions->attach($workerSession, [$readSub, $errSub, $frames]);
        }
    }
    
    private function cancelTimeout(Call $call) {
        if ($this->timeoutSubscriptions->contains($call)) {
            $subscription = $this->timeoutSubscriptions->offsetGet($call);
            $subscription->cancel();
            $this->timeoutSubscriptions->detach($call);
        }
    }
    
    private function handleUserlandError(WorkerSession $workerSession, \Exception $e) {
        list($call, $callId) = $this->workerSessionCallMap->offsetGet($workerSession);
        $this->cancelTimeout($call);
        $call->onError($callId, $e);
        $this->checkin($workerSession);
    }
    
    private function handleInternalError(WorkerSession $workerSession, \Exception $e) {
        if (!$this->availableWorkerSessions->contains($workerSession)) {
            list($call, $callId) = $this->workerSessionCallMap->offsetGet($workerSession);
            $this->cancelTimeout($call);
            $call->onError($callId, $e);
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

