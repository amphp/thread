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
     * Implementors SHOULD auto-start the thread pool if workers have not been spawned when this
     * method is invoked.
     *
     * @param string $procedure The name of the function to invoke
     * @param mixed $varArgs A variable-length argument list to pass the procedure
     * @throws \InvalidArgumentException if the final parameter is not a valid callback
     * @return \Amp\Future
     */
    function call($procedure, $varArgs = NULL);

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
