<?php

namespace Amp\Async\Processes;

class WorkerFactory {

    function __invoke($cmd, $cwd = NULL) {
        return new Worker($cmd, $cwd);
    }
    
}

