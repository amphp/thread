<?php

namespace Amp\Messaging;

interface Call {

    function getPayload();
    function onSuccess($callId, Message $msg);
    function onError($callId, \Exception $e);
    
}

