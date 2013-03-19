<?php

namespace Amp\Async\Processes;

use Amp\Async\Processes\Io\FrameParser,
    Amp\Async\Processes\Io\FrameWriter;

class WorkerSessionFactory {
    
    function __invoke($cmd, $cwd = NULL) {
        $worker = new Worker($cmd, $cwd);
        
        list($writePipe, $readPipe) = $worker->getPipes();
        
        $writer = new FrameWriter($writePipe);
        $parser = new FrameParser($readPipe);
        
        return new WorkerSession($worker, $parser, $writer);
    }
    
}

