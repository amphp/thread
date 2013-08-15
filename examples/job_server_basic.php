<?php

/**
 * examples/job_server_basic.php
 * 
 * IMPORTANT: This example assumes that you've started an AMP job server (bin/amp.php) on port 1337.
 * If you haven't started a job server the example will simply terminate with a connect failure
 * message because it can't reach any servers.
 * 
 * -------------------------------------------------------------------------------------------------
 * 
 * This example demonstrates dispatching four slow calls in parallel to a job server. If we did this
 * synchronously our script would need ~4 seconds to complete. Using AMP we're able to turn things
 * around in approximately ~1 second.
 * 
 * We dispatch four calls here because that is the default number of worker processes spawned by the
 * job server. If you want to handle more than four simultaneous calls you should specify that when
 * starting the job server.
 * 
 * Finally, note that this example connects to the job server synchronously; i.e. the connect call
 * does not return until the connection is established. This is usually preferable in web SAPI apps
 * where you don't want to proceed until you have a connection to the job server. Note that
 * connections may also be established asynchronously via UnserializedJobDispatcher::connectToJobServerAsync().
 */

require __DIR__ . '/../vendor/autoload.php';

use Alert\ReactorFactory, Amp\CallResult, Amp\JobDispatcher, Amp\ResourceException;

$reactor = (new ReactorFactory)->select();
$dispatcher = new JobDispatcher($reactor);

// Enable debug output in the console
$dispatcher->setOption('debug', TRUE);

try {
    $dispatcher->connectToJobServer('127.0.0.1:1337', $timeout = 1);
} catch (ResourceException $e) {
    die($e->getMessage());
}

$completedCallCount = 0;
$onResult = function(CallResult $callResult) use ($reactor, &$completedCallCount) {
    if (++$completedCallCount === 4) {
        echo "Yo dawg, I heard you like callbacks ...\n";
        echo "So I wrote a lib to let you invoke callbacks from inside your callbacks.\n";
        $reactor->stop();
    }
};

$reactor->immediately(function() use ($dispatcher, $onResult) {
    $dispatcher->call($onResult, 'sleep', 1); // returns immediately, executes in parallel
    $dispatcher->call($onResult, 'sleep', 1); // returns immediately, executes in parallel
    $dispatcher->call($onResult, 'sleep', 1); // returns immediately, executes in parallel
    $dispatcher->call($onResult, 'sleep', 1); // returns immediately, executes in parallel
});

$reactor->run();
