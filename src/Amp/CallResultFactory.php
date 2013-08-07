<?php

namespace Amp;

class CallResultFactory extends UnserializedCallResultFactory {
    
    function make($callId, $result, $error) {
        $result = ($result !== NULL) ? unserialize($result) : NULL;
        return new CallResult($callId, $result, $error);
    }
    
}
