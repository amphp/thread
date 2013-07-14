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
     * Attach an array of observation listener callbacks
     * 
     * @param array $listeners A key-value array mapping event names to callable listeners
     */
    function addObserver(array $listeners);
    
    /**
     * Cancel the specified observation
     * 
     * @param Observation $observation
     */
    function removeObserver(Observation $observation);
    
    /**
     * Cancel all existing observations watching this observable subject
     */
    function removeAllObservers();
    
}
