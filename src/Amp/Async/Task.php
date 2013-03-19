<?php

namespace Amp\Async;

class Task implements Dispatchable {
    
    private $procedure;
    private $workload;
    private $onSuccess;
    private $onError;
    
    function __construct($procedure, $workload, callable $onSucces, callable $onError) {
        $this->procedure = $procedure;
        $this->workload = $workload;
        $this->onSuccess = $onSucces;
        $this->onError = $onError;
    }
    
    function getProcedure() {
        return $this->procedure;
    }
    
    function getWorkload() {
        return $this->workload;
    }
    
    function onSuccess($result, $callId) {
        $onSuccess = $this->onSuccess;
        return $onSuccess($result, $callId);
    }
    
    function onError(\Exception $e, $callId) {
        $onError = $this->onError;
        return $onError($e, $callId);
    }
    
}

