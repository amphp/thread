<?php

namespace Amp\Messaging;

class WorkerFactory {

    function __invoke($cmd, $cwd = NULL) {
        return new Worker($cmd, $cwd);
    }
    
}

