<?php

namespace Amp\Messaging;

class Worker {
    
    const WRITE_PIPE = 0;
    const READ_PIPE  = 1;
    const ERROR_PIPE = 2;
    
    private $process;
    private $pipes = [];
    private static $descriptors = [
        self::WRITE_PIPE => ["pipe", "r"],
        self::READ_PIPE  => ["pipe", "w"],
        self::ERROR_PIPE => ["pipe", "w"]
    ];
    
    function __construct($command, $cwd = NULL) {
        $cwd = $cwd ?: getcwd();
        $this->process = proc_open($command, self::$descriptors, $this->pipes, $cwd);
        
        if (!is_resource($this->process)) {
            throw new \RuntimeException(
                'Failed spawning AMP worker process'
            );
        }
        
        stream_set_blocking($this->pipes[self::WRITE_PIPE], FALSE);
        stream_set_blocking($this->pipes[self::READ_PIPE],  FALSE);
        stream_set_blocking($this->pipes[self::ERROR_PIPE], FALSE);
    }
    
    function getWritePipe() {
        return $this->pipes[self::WRITE_PIPE];
    }
    
    function getReadPipe() {
        return $this->pipes[self::READ_PIPE];
    }
    
    function getErrorPipe() {
        return $this->pipes[self::ERROR_PIPE];
    }
    
    function getPipes() {
        return $this->pipes;
    }
    
    function __destruct() {
        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        
        proc_close($this->process);
    }
    
}

