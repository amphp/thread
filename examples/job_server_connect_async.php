<?php

/**
 * examples/job_server_connect_async.php
 * 
 * IMPORTANT: This example assumes that you've started an AMP job server (bin/amp.php) on port 1337.
 * If you haven't started a job server the example will simply terminate with a connect failure
 * message because it can't reach any servers.
 * 
 * -------------------------------------------------------------------------------------------------
 * 
 * While SAPI applications may prefer synchronous connection attempts, you can also connect to job
 * servers asynchronously. Simply invoke `UnserializedJobDispatcher::connectToJobServerAsync()` with the server
 * address and a callback to be notified when the connection is resolved. The connect call will
 * return immediately and the callback will be notified with a single boolean TRUE or FALSE argument
 * when the connection succeeds or fails.
 * 
 * Note that by default connections to the job server are kept alive. If a connection dies the 
 * the job dispatcher will asynchronously attempt to reconnect using an exponential backoff
 * algorithm until a predetermined maximum reconnection attempts have been exhausted. These options
 * can be configured using the job dispatcher's `setOption()` and `setAllOptions()` methods.
 * 
 * Finally, it should be mentioned that clients can connect to as many simultaneous job servers as
 * they like at one time and dispatchers will distribute new requests to the job server with the
 * fewest outstanding calls.
 */

require __DIR__ . '/../vendor/autoload.php';

use Alert\ReactorFactory, Amp\CallResult, Amp\JobDispatcher;

$reactor = (new ReactorFactory)->select();
$dispatcher = new JobDispatcher($reactor);

// Enable debug output in the console
$dispatcher->setOption('debug', TRUE);

// What we'll do when the call result comes back from the job server ...
$onCallResult = function(CallResult $callResult) use ($reactor) {
    var_dump($callResult->getResult());
    $reactor->stop();
};

// What we'll do when our connection succeeds or fails
$onConnect = function($success) use ($dispatcher, $onCallResult) {
    if ($success) {
        $dispatcher->call($onCallResult, 'strrev', 'zanzibar!');
    } else {
        die('Connection to the job server failed :(');
    }
};

// Schedule the async connect attempt to fire immediately when the reactor starts
$reactor->immediately(function() use ($dispatcher, $onConnect) {
    $dispatcher->connectToJobServerAsync('127.0.0.1:1337', $onConnect);
});

$reactor->run();
