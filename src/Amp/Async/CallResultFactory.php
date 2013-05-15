<?php

namespace Amp\Async;

class CallResultFactory {
    
    function make($callId, $result, $error) {
        return new CallResult($callId, $result, $error);
    }
    
}
