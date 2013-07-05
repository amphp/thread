<?php

class StaticMailWorker {
    
    static function send($to, $from, $subject, $body, $headers = []) {
        // ... construct and send an email from the passed args
        
        // The return value is optional and will be accessible in the main process via
        // the CallResult::getResult() method when the call returns.
        //
        // If an uncaught exception is thrown during invocation it will be caught in the
        // worker process and this call will be represented by an error result in the
        // main process (CallResult::isError() and CallResult::getError())
        
        return TRUE;
    }
}

