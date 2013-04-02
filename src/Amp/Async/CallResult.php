<?php

namespace Amp\Async;

class CallResult {
    
    private $result;
    private $error;
    
    final function __construct($callId, $result, \Exception $error = NULL, $isComplete = TRUE) {
        $this->callId = $callId;
        $this->result = $result;
        $this->error = $error;
        $this->isComplete = $isComplete;
    }
    
    /**
     * Retrieve the call ID associated with this result
     * 
     * @return string Returns the packed 32-bit integer call ID
     */
    final function getCallId() {
        return $this->callId;
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
     * Did the invocation fail?
     * 
     * @return bool Returns TRUE for invocation failure or FALSE otherwise
     */
    final function isError() {
        return (bool) $this->error;
    }
    
    /**
     * Is more data coming before the call result is complete?
     * 
     * @return bool Returns TRUE if this is the last result chunk for this call, FALSE otherwise
     */
    final function isComplete() {
        return $this->isComplete;
    }
}

