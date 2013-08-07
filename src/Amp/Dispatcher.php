<?php

namespace Amp;

interface Dispatcher {

    /**
     * Asynchronously execute a procedure and invoke the $onResult callback when complete
     *
     * @param callable $onResult The callback to process the resulting CallResult
     * @param string $procedure  The function to execute asynchronously
     */
    function call($onResult, $procedure);

}
