<?php

/**
 * @TODO Explain the demo
 */

use Amp\IoDispatcher, Amp\CallResult, Alert\ReactorFactory;

require dirname(__DIR__) . '/autoload.php';
require __DIR__ . '/support/async_mailer/MyAsyncMailer.php';

$reactor = (new ReactorFactory)->select();
$include = __DIR__ . '/support/async_mailer/StaticMailWorker.php';
$dispatcher = new IoDispatcher($reactor, $include, $workerProcessesToSpawn = 8);

$mailer = new MyAsyncMailer($reactor, $dispatcher);

$mailJobs = [
    [$to ='test1@test.com', $from='address@me.com', $subject='my test', $body='body', $headers=[]],
    [$to ='test2@test.com', $from='address@me.com', $subject='my test', $body='body', $headers=[]],
    [$to ='test3@test.com', $from='address@me.com', $subject='my test', $body='body', $headers=[]],
    [$to ='test4@test.com', $from='address@me.com', $subject='my test', $body='body', $headers=[]],
    [$to ='test5@test.com', $from='address@me.com', $subject='my test', $body='body', $headers=[]],
    [$to ='test6@test.com', $from='address@me.com', $subject='my test', $body='body', $headers=[]],
    [$to ='test7@test.com', $from='address@me.com', $subject='my test', $body='body', $headers=[]],
    [$to ='test8@test.com', $from='address@me.com', $subject='my test', $body='body', $headers=[]]
];

// Kick things off when the reactor starts
$reactor->immediately(function() use ($mailer, $mailJobs) {
    $mailer->dispatch($mailJobs);
});

// Release the hounds!
$reactor->run();
