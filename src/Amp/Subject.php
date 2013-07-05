<?php

namespace Amp;

/**
 * Implements the Observable interface allowing objects to accept, manage and notify observers.
 */
trait Subject {
    
    private $observers;
    
    function observe(array $eventListenerMap) {
        $observation = new Observation($this, $eventListenerMap);
        $this->observers = $this->observers ?: new \SplObjectStorage;
        $this->observers->attach($observation);
        
        return $observation;
    }
    
    function forget(Observation $observation) {
        if ($this->observers) {
            $this->observers->detach($observation);
        }
    }
    
    function forgetAll() {
        $this->observers = new \SplObjectStorage;
    }
    
    protected function notify($event, $data = NULL) {
        if ($this->observers) {
            foreach ($this->observers as $observation) {
                $observation($event, $data);
            }
        }
    }
    
}

