<?php

namespace Amp\Async;

interface Dispatcher {

    /**
     * Asynchronously call a dispatchable task
     * 
     * @param Dispatchable $task The dispatchable task instance to execute
     * @return string Returns the task's call ID
     */
    function call(Dispatchable $task);
    function start();
    function stop();
    
}

