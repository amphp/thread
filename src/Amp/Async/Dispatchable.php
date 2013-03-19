<?php

namespace Amp\Async;

use Exception;

interface Dispatchable {
    
    /**
     * The string function to execute asynchronously
     */
    function getProcedure();
    
    /**
     * The workload arguments to pass the executable procedure -- must be JSON-encodable
     */
    function getWorkload();
    
    /**
     * Invoked on successful procedure invocation
     */
    function onSuccess($result, $callId);
    
    /**
     * Invoked on procedure invocation failure or execution timeout
     */
    function onError(Exception $e, $callId);
    
}

