<?php

namespace Amp\MultiProcess;

class WorkerSessionFactory {
    
    private $granularity = 65536;
    
    function __invoke($cmd, $errorStream = NULL, $cwd = NULL) {
        $worker = new Worker($cmd, $errorStream, $cwd);
        
        list($writePipe, $readPipe) = $worker->getPipes();
        
        $writer = new FrameWriter($writePipe);
        $parser = new FrameParser($readPipe);
        
        $writer->setGranularity($this->granularity);
        $parser->setGranularity($this->granularity);
        
        return new WorkerSession($worker, $parser, $writer);
    }
    
    function setGranularity($bytes) {
        $this->granularity = filter_var($bytes, FILTER_VALIDATE_INT, ['options' => [
            'min_range' => 1,
            'default' => 65536
        ]]);
    }
    
}

