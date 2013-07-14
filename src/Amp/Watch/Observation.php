<?php

namespace Amp\Watch;

class Observation {
    
    private $subject;
    private $observerCallbacks = array();
    private $isEnabled = TRUE;
    
    function __construct(Observable $subject, array $observerCallbacks) {
        $this->subject = $subject;
        $this->assignCallbacks($observerCallbacks);
    }
    
    private function assignCallbacks(array $observerCallbacks) {
        if (empty($observerCallbacks)) {
            throw new \InvalidArgumentException(
                'No observer event callbacks specified'
            );
        }
        
        foreach ($observerCallbacks as $event => $callback) {
            if (is_callable($callback)) {
                $this->observerCallbacks[$event] = $callback;
            } else {
                throw new \InvalidArgumentException(
                    'Invalid observation callback'
                );
            }
        }
    }
    
    function enable() {
        $this->isEnabled = TRUE;
    }
    
    function disable() {
        $this->isEnabled = FALSE;
    }
    
    function cancel() {
        $this->subject->removeObserver($this);
        $this->isEnabled = FALSE;
    }
    
    function modify(array $observerCallbacks) {
        $this->assignCallbacks($observerCallbacks);
    }
    
    function replace(array $observerCallbacks) {
        $this->observerCallbacks = array();
        $this->assignCallbacks($observerCallbacks);
    }
    
    function __invoke($event, $data = NULL) {
        if ($this->isEnabled && isset($this->observerCallbacks[$event])) {
            $callback = $this->observerCallbacks[$event];
            $callback($data);
        }
    }
    
}

