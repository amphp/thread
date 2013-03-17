<?php

namespace Amp\Messaging;

interface OnFrameCall extends Call {

    function onFrame($callId, Frame $frame);
    
}

