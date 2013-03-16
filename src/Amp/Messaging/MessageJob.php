<?php

namespace Amp\Messaging;

interface MessageJob {

    function getPayload();
    function onSuccess(Message $msg);
    function onError(\Exception $e);
    
}

