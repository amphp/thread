<?php

namespace Amp\Messaging;

class MessageFactory {

    function __invoke(array $frames) {
        return new Message($frames);
    }
    
}

