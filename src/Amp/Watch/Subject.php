<?php

namespace Amp\Watch;

/**
 * Implements the Observable interface allowing objects to accept, manage and notify observers.
 */
trait Subject {
    
    private $observers;
    
    function addObserver(array $eventListenerMap) {
        $observation = new Observation($this, $eventListenerMap);
        $this->observers = $this->observers ?: new \SplObjectStorage;
        $this->observers->attach($observation);
        
        return $observation;
    }
    
    function removeObserver(Observation $observation) {
        if ($this->observers) {
            $this->observers->detach($observation);
        }
    }
    
    function removeAllObservers() {
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
