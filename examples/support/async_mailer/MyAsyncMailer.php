<?php

use Amp\Dispatch\PhpDispatcher,
    Amp\Dispatch\CallResult;

class MyAsyncMailer {
    
    private $dispatcher;
    private $pendingCalls = [];
    
    function __construct(PhpDispatcher $dispatcher) {
        $this->dispatcher = $dispatcher;
    }
    
    function dispatch(array $mailJobs) {
        $onCallResult = function($result) { $this->onCallResult($result); };
        
        foreach ($mailJobs as $mailJob) {
            list($to, $from, $subject, $body, $headers) = $mailJob;
            
            $callId = $this->dispatcher->call(
                $onCallResult,
                'StaticMailWorker::send',
                $to,
                $from,
                $subject,
                $body,
                $headers
            );
            
            $this->pendingCalls[$callId] = $mailJob;
        }
    }
    
    private function onCallResult(CallResult $callResult) {
        $callId = $callResult->getCallId();
        $mailJob = $this->pendingCalls[$callId];
        
        unset($this->pendingCalls[$callId]);
        
        list($to, $from, $subject, $body, $headers) = $mailJob;
        
        if ($callResult->isSuccess()) {
            echo "Great success! {$to}; {$subject}", PHP_EOL;
        } else {
            echo "Mail fail! {$to} :(", PHP_EOL;
        }
        
        if (empty($this->pendingCalls)) {
            // If we don't stop the event reactor the program will run forever!
            $this->dispatcher->stopReactor();
        }
    }
    
}

