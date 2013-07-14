<?php

namespace Amp\Dispatch;

interface Dispatcher {
    
    /**
     * Asynchronously execute a procedure and invoke the $onResult callback when complete
     * 
     * @param callable $onResult The callback to process the resulting CallResult
     * @param string $procedure  The function to execute asynchronously
     * @param string $workload   The data to pass as an argument to the procedure
     */
    function call(callable $onResult, $procedure, $workload = NULL);
    
}
