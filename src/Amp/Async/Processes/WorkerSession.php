<?php

namespace Amp\Async\Processes;

use Amp\Async\Processes\Io\Frame,
    Amp\Async\Processes\Io\FrameParser,
    Amp\Async\Processes\Io\FrameWriter;

class WorkerSession {
    
    private $worker;
    private $parser;
    private $writer;
    
    function __construct(Worker $worker, FrameParser $parser, FrameWriter $writer) {
        $this->worker = $worker;
        $this->parser = $parser;
        $this->writer = $writer;
    }
    
    function parse() {
        return $this->parser->parse();
    }
    
    function write(Frame $frame = NULL) {
        return $this->writer->write($frame);
    }
    
    function getWritePipe() {
        return $this->worker->getWritePipe();
    }
    
    function getReadPipe() {
        return $this->worker->getReadPipe();
    }
    
    function getErrorPipe() {
        return $this->worker->getErrorPipe();
    }
    
    function getPipes() {
        return $this->worker->getPipes();
    }
    
}

