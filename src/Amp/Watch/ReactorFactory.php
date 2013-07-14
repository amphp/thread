<?php

namespace Amp\Watch;

class ReactorFactory {
    
    private $hasLibevent;
    
    function __construct() {
        $this->hasLibevent = extension_loaded('libevent');
    }
    
    function __invoke() {
        return $this->select();
    }
    
    function select() {
        return $this->hasLibevent ? new LibeventReactor : new NativeReactor;
    }
    
}
