<?php

namespace Amp\Async;

interface Dispatcher {
    
    /**
     * Asynchronously execute a procedure and handle the result using the $onResult callback
     * 
     * @param callable $onResult The callback to process the async execution's CallResult
     * @param string $procedureName The function to execute asynchronously
     * @param mixed $varArgs, ...
     * @return string Returns the task's call ID
     */
    function call(callable $onResult, $procedureName, $varArgs = NULL);
    function start();
    function stop();
    
}

