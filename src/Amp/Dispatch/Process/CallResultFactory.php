<?php

namespace Amp\Dispatch\Process;

class CallResultFactory {
    
    function make($callId, $result, $error) {
        return new CallResult($callId, $result, $error);
    }
    
}
