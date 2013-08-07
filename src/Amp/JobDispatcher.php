<?php

namespace Amp;

use Alert\Reactor;

class JobDispatcher extends UnserializedJobDispatcher {
    function __construct(Reactor $reactor) {
        parent::__construct($reactor, new CallResultFactory);
    }
}
