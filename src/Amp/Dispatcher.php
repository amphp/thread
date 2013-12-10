<?php

namespace Amp;

interface Dispatcher {

    /**
     * Spawn worker threads and start the dispatcher
     *
     * @param int $workerCount The number of worker threads to spawn
     */
    function start($workerCount);

    /**
     * Dispatch a call to the thread pool
     *
     * @param string $procedure The name of the function to invoke
     * @param mixed $varArgs A variable-length argument list to pass the procedure
     * @param callable $onResult The final argument is the callable to invoke with the invocation result
     */
    function call($procedure, $varArgs /* ..., $argN, callable $onResult*/);

    /**
     * Configure dispatcher options
     *
     * @param string $option A case-insensitive option key
     * @param mixed $value The value to assign
     */
    function setOption($option, $value);

}
