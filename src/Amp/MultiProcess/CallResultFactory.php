<?php

namespace Amp\MultiProcess;

class CallResultFactory {
    
    function make($callId, $result, $error) {
        return new CallResult($callId, $result, $error);
    }
    
}
