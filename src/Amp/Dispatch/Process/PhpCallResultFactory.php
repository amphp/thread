<?php

namespace Amp\Dispatch\Process;

class PhpCallResultFactory extends CallResultFactory {
    
    function make($callId, $result, $error) {
        $result = ($result !== NULL) ? unserialize($result) : NULL;
        return new CallResult($callId, $result, $error);
    }
    
}
