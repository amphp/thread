<?php

namespace Amp\Async;

class Worker {
    
    const WRITE_PIPE = 0;
    const READ_PIPE  = 1;
    const ERROR_PIPE = 2;
    
    private $process;
    private $pipes = [];
    private $descriptors = [
        self::WRITE_PIPE => ["pipe", "r"],
        self::READ_PIPE  => ["pipe", "w"],
        self::ERROR_PIPE => NULL
    ];
    
    function __construct($command, $errorStream = NULL, $cwd = NULL) {
        $this->descriptors[self::ERROR_PIPE] = $errorStream ?: STDERR;
        $this->process = proc_open($command, $this->descriptors, $this->pipes, $cwd ?: getcwd());
        
        if (!is_resource($this->process)) {
            throw new \RuntimeException(
                'Failed spawning AMP worker process'
            );
        }
        
        stream_set_blocking($this->pipes[self::WRITE_PIPE], FALSE);
        stream_set_blocking($this->pipes[self::READ_PIPE],  FALSE);
    }
    
    function getWritePipe() {
        return $this->pipes[self::WRITE_PIPE];
    }
    
    function getReadPipe() {
        return $this->pipes[self::READ_PIPE];
    }
    
    function getPipes() {
        return $this->pipes;
    }
    
    function __destruct() {
        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                @fclose($pipe);
            }
        }
        
        proc_terminate($this->process);
    }
    
}

