<?php

namespace Amp;

class Process {
    
    public $process;
    public $command;
    public $pid;
    public $stdin;
    public $stdout;
    private $isDestructed = FALSE;
    
    function __construct($command, $errorStream, $cwd = NULL) {
        $descriptors = array(
            array("pipe", "r"),
            array("pipe", "w"),
            $errorStream
        );
        
        $this->process = proc_open($command, $descriptors, $pipes, $cwd ?: getcwd());
        
        if (is_resource($this->process)) {
            list($this->stdin, $this->stdout) = $pipes;
            $this->command = $command;
            $this->pid = proc_get_status($this->process)['pid'];
        } else {
            throw new \RuntimeException(
                'Failed spawning process'
            );
        }
    }
    
    /**
     * We sometimes need to manually call this method from our shutdown handler to prevent fatal
     * errors in the main process from leaving zombie workers lying around. As a result, the 
     * isDestructed flag is enabled to avoid doubling our efforts in extreme cases.
     */
    function __destruct() {
        if ($this->isDestructed) {
            return;
        }
        
        if (is_resource($this->stdin)) {
            @fclose($this->stdin);
        }
        
        if (is_resource($this->stdout)) {
            @fclose($this->stdout);
        }
        
        if (proc_get_status($this->process)['running']) {
            @proc_terminate($this->process);
        }
        
        $this->isDestructed = TRUE;
    }
    
}

