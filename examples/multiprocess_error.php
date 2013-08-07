<?php

/**
 * Any errors triggered during worker process execution are automatically written to the main
 * process's STDERR stream. The userland function `my_error_function()` used for this example
 * purposefully triggers a user notice. As a result, you'll see that error output in your console
 * (in addition to the function's return value). If you execute this script behind a web SAPI and
 * run it in a browser the error message will instead be directed to your web server's error log.
 */

use Alert\ReactorFactory, Amp\IoDispatcher, Amp\CallResult;

require dirname(__DIR__) . '/autoload.php';

$reactor = (new ReactorFactory)->select();
$asyncFunctions  = __DIR__ . '/support/my_async_functions.php';
$dispatcher = new IoDispatcher($reactor, $asyncFunctions, $workerProcessesToSpawn = 1);

// ------------------------------------------------------------------------------------------------>

$onResult = function(CallResult $r) use ($reactor) {
    $isWin = (stripos(PHP_OS, 'win') === 0);
    $msg = "\n^^ STDERR output passes through from the worker process\n\n";
    echo $isWin ? $msg : "\033[1;33m{$msg}\033[0m";
    echo "ASYNC RESULT: ", $r->getResult(), "\n\n";
    $reactor->stop();
};

$reactor->immediately(function() use ($dispatcher, $onResult) {
    $dispatcher->call($onResult, 'my_error_function');
});

// ------------------------------------------------------------------------------------------------>

$reactor->run();
