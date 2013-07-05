<?php

namespace Amp;

interface Observable {
    
    const READY = 'ready';
    const DATA = 'data';
    const SEND = 'send';
    const DRAIN = 'drain';
    const ERROR = 'error';
    const DONE = 'done';
    const START = 'start';
    const STOP = 'stop';
    
    /**
     * Attach an array of observation listeners
     * 
     * @param array $listeners A key-value array mapping event names to callable listeners
     */
    function observe(array $listeners);
    
    /**
     * Cancel the specified observation
     * 
     * @param Observation $observation
     */
    function forget(Observation $observation);
    
    /**
     * Cancel all existing observations watching this observable subject
     */
    function forgetAll();
    
    /**
     * Notify observers of an event
     * 
     * @param string $event
     * @param mixed $data Data associated with this event
     */
    function notify($event, $data = NULL);
    
}

