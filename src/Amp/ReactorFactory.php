<?php

namespace Amp;

class ReactorFactory {
    
    function __invoke() {
        return $this->select();
    }
    
    function select() {
        return $this->hasLibevent() ? new LibeventReactor : new NativeReactor;
    }
    
    /**
     * This method exists to mock the presence of the libevent extension in unit tests
     */
    protected function hasLibevent() {
        return extension_loaded('libevent');
    }
}

