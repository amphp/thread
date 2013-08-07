<?php

use Alert\Reactor,
    Amp\IoDispatcher,
    Amp\CallResult;

class MyAsyncMailer {

    private $dispatcher;
    private $pendingCalls = [];

    function __construct(Reactor $reactor, IoDispatcher $dispatcher) {
        $this->reactor = $reactor;
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

        echo $callResult->isSuccess()
            ? "Great success! {$to}; {$subject}" . PHP_EOL
            : "Mail fail! {$to} :(" . PHP_EOL;

        if (empty($this->pendingCalls)) {
            $this->reactor->stop(); // Stop the event reactor the program will run forever!
        }
    }

}
