<?php

class AsyncFunctionCall implements Amp\Messaging\Call {
    
    private $payload;
    
    function __construct($procedure, $args = NULL) {
        if (!is_string($procedure)) {
            throw new \InvalidArgumentException(
                __CLASS__ . '::__construct requires a string procedure name at Argument 1'
            );
        }
        
        if (!$args || is_scalar($args)) {
            $args = [$args];
        } elseif (!is_array($args)) {
            throw new \InvalidArgumentException(
                __CLASS__ . '::__construct requires a scalar or array value at Argument 2'
            );
        }
        
        if (FALSE === ($this->payload = json_encode([$procedure, $args]))) {
            throw new \InvalidArgumentException(
                'Failed encoding procedure arguments to JSON for transport'
            );
        }
    }
    
    function getPayload() {
        return $this->payload;
    }
    
    function onSuccess($callId, Amp\Messaging\Message $msg) {
        echo "msg rcvd: ";
        var_dump(json_decode($msg->getPayload()));
    }
    
    function onError($callId, Exception $e) {
        echo "error: ", $e->getMessage(), "\n";
    }
    
}

