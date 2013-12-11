<?php

namespace Amp;

class CallResult {

    private $callId;
    private $data;
    private $error;

    function __construct($callId, $data = NULL, \Exception $error = NULL) {
        $this->callId = $callId;
        $this->data = $data;
        $this->error = $error;
    }

    function getResult() {
        if (!$this->error) {
            return $this->data;
        } else {
            throw new DispatcherException(
                $msg = 'Dispatch failure',
                $code = 0,
                $this->error
            );
        }
    }

    function getError() {
        return $this->error;
    }

    function succeeded() {
        return !$this->error;
    }

    function failed() {
        return (bool) $this->error;
    }

    function cancelled() {
        return $this->error && $this->error instanceof CallCancelledException;
    }

    function getCallId() {
        return $this->callId;
    }

}