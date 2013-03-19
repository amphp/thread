<?php

class MyAsyncFunctionCall extends Amp\Async\Task {
    
    function __construct($procedure) {
        $args = func_get_args();
        array_shift($args);
        
        $onSuccess = function($response, $callId) {
            echo "call response rcvd: ";
            var_dump($response);
        };
        
        $onError = function(Exception $e, $callId) {
            echo "error: ", $e->getMessage(), "\n";
        };
        
        parent::__construct($procedure, $args, $onSuccess, $onError);
    }
    
}

