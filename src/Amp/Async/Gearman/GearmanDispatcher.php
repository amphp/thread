<?php

namespace Amp\Async\Gearman;

use Amp\Async\Dispatcher,
    Amp\Async\Dispatchable;

/**
 * @TODO Implement me!
 */
class GearmanDispatcher implements Dispatcher {

    function call(callable $onResult, $procedureName, $varArgs = NULL) {}
    function dispatch(Dispatchable $task) {}
    function start() {}
    function stop() {}
    
}

