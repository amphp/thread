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
     * The final argument to Dispatcher::call must be a valid callback to which the Dispatcher will
     * pass the CallResult upon completion.
     *
     * Though it can't be contracted in an interface, API users should expect Dispatcher::call to
     * return a unique integer identifying the dispatched call. If the Dispatcher cannot fulfill the
     * call due to load a zero value (0) should be returned to allow boolean logic.
     *
     * @param string $procedure The name of the function to invoke
     * @param mixed $varArgsAndCallback A variable-length argument list to pass the procedure
     * @param callable $onResult The final argument is the callable to invoke with the invocation result
     */
    function call($procedure, $varArgsAndCallback /*..., $argN, callable $onResult*/);

    /**
     * Cancel a previously dispatched call
     *
     * @param int $callId The call to be cancelled
     */
    function cancel($callId);

    /**
     * Configure dispatcher options
     *
     * @param string $option A case-insensitive option key
     * @param mixed $value The value to assign
     */
    function setOption($option, $value);

}
