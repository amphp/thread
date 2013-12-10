<?php

namespace Amp;

class DispatchResult {

    private $data;
    private $error;

    function __construct($data = NULL, \Exception $error = NULL) {
        $this->data = $data;
        $this->error = $error;
    }

    function getResult() {
        if (!$this->error) {
            return $this->data;
        } else {
            throw new DispatchException(
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

}