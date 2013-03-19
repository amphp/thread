<?php

namespace Amp\Async;

class IncrementalTask extends Task implements IncrementalDispatchable {
    
    function __construct($procedure, $workload, callable $onSucces, callable $onError, callable $onIncrement) {
        parent::__construct($procedure, $workload, $onSucces, $onError);
        $this->onIncrement = $onIncrement;
    }
    
    function onIncrement($partialResult, $callId) {
        $onIncrement = $this->onIncrement;
        return $onIncrement($partialResult, $callId);
    }
    
}

