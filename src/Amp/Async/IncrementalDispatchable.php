<?php

namespace Amp\Async;

interface IncrementalDispatchable extends Dispatchable {
    
    /**
     * Receive incremental data prior to dispatch response completion
     */
    function onIncrement($resultPart, $callId);
    
}

