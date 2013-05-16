<?php

/**
 * Any errors triggered during worker process execution are automatically written to the main
 * process's STDERR stream. The userland function `my_error_function()` used for this example
 * purposefully triggers a user notice. As a result, you'll see that error output in your console in
 * addition to the function's return value.
 */

use Amp\MultiProcess\PhpDispatcher,
    Amp\MultiProcess\CallResult,
    Amp\ReactorFactory;

require dirname(__DIR__) . '/autoload.php';

$asyncFunctions  = __DIR__ . '/support_files/my_async_functions.php';
$reactor = (new ReactorFactory)->select();
$dispatcher = new PhpDispatcher($reactor, $asyncFunctions, $poolSize = 1);
$dispatcher->start();

// ------------------------------------------------------------------------------------------------>

$onResult = function(CallResult $r) use ($reactor) {
    echo "ASYNC RESULT: ", $r->getResult(), "\n\n";
    $reactor->stop();
};

$reactor->once(function() use ($dispatcher, $onResult) {
    $dispatcher->call($onResult, 'my_error_function');
});

// ------------------------------------------------------------------------------------------------>

$reactor->run();

