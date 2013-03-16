<?php

namespace Amp\Reactor;

class ReactorFactory {
    
    /**
     * @TODO Select best available event base for the current system. Right now the only one that
     *       exists is the LibEventReactor. At the very least add a reactor utilizing the native
     *       `stream_select` so that the library is usable without PECL libevent.
     */
    function select() {
        return new LibEventReactor;
    }
    
}

