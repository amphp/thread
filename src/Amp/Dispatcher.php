<?php

namespace Amp;

interface Dispatcher extends \Countable {

    /**
     * Initialization resources and start the dispatcher
     */
    function start();

    /**
     * Dispatch a task to the thread pool
     *
     * The final argument to Dispatcher::call must be a valid callback to which the Dispatcher will
     * pass the CallResult upon completion.
     *
     * Though it can't be contracted in an interface, API users should expect Dispatcher::call to
     * return a unique integer identifying the dispatched task. If the Dispatcher cannot fulfill the
     * task due to load a zero value (0) should be returned to allow boolean logic.
     *
     * @param string $procedure The name of the function to invoke
     * @param mixed $varArgsAndCallback A variable-length argument list to pass the procedure
     * @param callable $onResult The final argument is the callable to invoke with the invocation result
     * @return int Returns a unique integer task ID identifying this task
     */
    function call($procedure, $varArgsAndCallback /*..., $argN, callable $onResult*/);

    /**
     * Cancel a previously dispatched task
     *
     * @param int $taskId The task to be cancelled
     * @return bool Return TRUE if the specified task existed and was cancelled, FALSE otherwise.
     */
    function cancel($taskId);

    /**
     * Retrieve a count of all outstanding tasks (queued and in-progress)
     *
     * @return int
     */
    function count();

    /**
     * Configure dispatcher options
     *
     * @param string $option A case-insensitive option key
     * @param mixed $value The value to assign
     */
    function setOption($option, $value);

    /**
     * Delegate nonexistent methods to globally namespaced functions
     *
     * @param string $method
     * @param array $args
     * @return int Returns task ID associated with the resulting call
     */
    function __call($method, $args);

}
