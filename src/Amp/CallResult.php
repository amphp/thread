<?php

namespace Amp;

class CallResult {

    private $callId;
    private $result;
    private $error;

    final function __construct($callId, $result, \Exception $error = NULL) {
        $this->callId = $callId;
        $this->result = $result;
        $this->error = $error;
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
     * @throws CallException if the call resulted in an error
     * @return mixed Returns the result of the asynchronously invoked procedure
     */
    final function getResult() {
        if ($this->error === NULL) {
            return $this->result;
        } else {
            throw $this->error;
        }
    }

    /**
     * Retrieve the Exception object responsible for call failure
     *
     * @return string Returns the error message explaining the call's failure
     */
    final function getError() {
        return $this->error;
    }

    /**
     * Was the invocation successful?
     *
     * @return bool Returns TRUE for successful async invocation or FALSE for failure
     */
    final function isSuccess() {
        return ($this->error === NULL);
    }

    /**
     * Did the invocation fail?
     *
     * @return bool Returns TRUE if the call failed, FALSE otherwise
     */
    final function isError() {
        return (bool) $this->error;
    }

}
