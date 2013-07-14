<?php

namespace Amp\Watch;

class Alarm {
    
    private $callback;
    private $interval;
    private $nextExecutionAt;
    private $executionCount = 0;
    
    function __construct(callable $callback, $interval) {
        $this->callback = $callback;
        $this->interval = round($interval, 4);
    }
    
    function start() {
        $this->nextExecutionAt = $this->nextExecutionAt ?: (microtime(TRUE) + $this->interval);
    }
    
    function stop() {
        $this->nextExecutionAt = NULL;
    }
    
    function execute($microtime) {
        if ($this->nextExecutionAt && $this->nextExecutionAt <= $microtime) {
            $this->executionCount++;
            $this->nextExecutionAt = ($microtime + $this->interval);
            
            $callback = $this->callback;
            $callback();
        }
        
        return $this->executionCount;
    }
    
    function getNextScheduledExecutionTime() {
        return $this->nextExecutionAt;
    }
    
}

