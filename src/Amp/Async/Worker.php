<?php

namespace Amp\Async;

class Worker {
    
    const STDIN  = 0;
    const STDOUT = 1;
    const STDERR = 2;
    
    private $process;
    private $pipes = [];
    private $descriptors = [
        self::STDIN  => ["pipe", "r"],
        self::STDOUT => ["pipe", "w"],
        self::STDERR => NULL
    ];
    
    function __construct($command, $errorStream = NULL, $cwd = NULL) {
        $this->descriptors[self::STDERR] = $errorStream ?: STDERR;
        $this->process = proc_open($command, $this->descriptors, $this->pipes, $cwd ?: getcwd());
        
        // @codeCoverageIgnoreStart
        if (!is_resource($this->process)) {
            throw new \RuntimeException(
                'Failed spawning AMP worker process'
            );
        }
        // @codeCoverageIgnoreEnd
        
        stream_set_blocking($this->pipes[self::STDIN], FALSE);
        stream_set_blocking($this->pipes[self::STDOUT], FALSE);
    }
    
    function getWritePipe() {
        return $this->pipes[self::STDIN];
    }
    
    function getReadPipe() {
        return $this->pipes[self::STDOUT];
    }
    
    function getPipes() {
        return $this->pipes;
    }
    
    function getStatus() {
        return proc_get_status($this->process);
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

