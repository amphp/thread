<?php

namespace Amp\Async;

class CallResult {
    
    private $result;
    private $error;
    
    final function __construct($result, \Exception $error = NULL) {
        $this->result = $result;
        $this->error = $error;
    }
    
    /**
     * Retrieve the async invocation result
     * 
     * @throws \Exception if the call resulted in an error
     * @return mixed Returns the result of the asynchronously invoked procedure
     */
    final function getResult() {
        if (!$this->error) {
            return $this->result;
        } else {
            throw $this->error;
        }
    }
    
    /**
     * Was the invocation successful?
     * 
     * @return bool Returns TRUE for successful async invocation or FALSE for failure
     */
    final function isSuccess() {
        return !$this->error;
    }
    
    /**
     * Retrieve the exception instance that caused the call's failure
     * 
     * @return \Exception Returns an Exception instance or NULL if no error occured
     */
    final function getError() {
        return $this->error;
    }

}

