<?php

namespace Amp;

interface ThreadDispatcher extends Dispatcher {

    /**
     * Dispatch a Stackable task to the thread pool for processing
     *
     * @param \Stackable $task
     * @param int $priority
     * @return \Amp\Future
     */
    function execute(\Stackable $task, $priority = 50);

    /**
     * Allow threaded Stackable execution via magic __invoke()
     *
     * This method is an analog for ThreadDispatcher::execute()
     *
     * @param \Stackable $task
     * @param int $priority
     * @return \Amp\Future
     */
    function __invoke(\Stackable $task, $priority = 50);

}
