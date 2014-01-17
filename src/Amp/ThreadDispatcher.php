<?php

namespace Amp;

interface ThreadDispatcher extends Dispatcher {

    /**
     * Dispatch a Stackable task to the thread pool for processing
     *
     * @param \Stackable $task
     * @param callable $onResult
     * @return int Returns a unique integer task ID identifying this call
     */
    function execute(\Stackable $task, callable $onResult);

    /**
     * Allow threaded Stackable execution via magic __invoke()
     *
     * This method is an analog for ThreadDispatcher::execute()
     *
     * @param \Stackable $task
     * @param callable $onResult
     * @return int Returns a unique integer task ID identifying this call
     */
    function __invoke(\Stackable $task, callable $onResult);

}
