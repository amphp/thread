<?php

namespace Amp\MultiProcess;

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
    
    function write($callFrame = NULL) {
        return $this->writer->write($callFrame);
    }
    
    function getWritePipe() {
        return $this->worker->getWritePipe();
    }
    
    function getReadPipe() {
        return $this->worker->getReadPipe();
    }
    
    function getPipes() {
        return $this->worker->getPipes();
    }
    
}

