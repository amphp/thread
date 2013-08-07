<?php

namespace Amp;

class UnserializedCallResultFactory {

    function make($callId, $result, $error) {
        return new CallResult($callId, $result, $error);
    }

}
