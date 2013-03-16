<?php

namespace Amp\Messaging;

interface Call {

    function getPayload();
    function onSuccess(Message $msg);
    function onError(\Exception $e);
    
}

