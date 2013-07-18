<?php

require dirname(__DIR__) . '/autoload.php';







define('RUN_FOR', 3);
$reactor = (new Amp\ReactorFactory)->select();
var_dump(get_class($reactor));
$count = 0;
$counter = function() use (&$count) { $count++; };
$reactor->schedule($counter, $delay = 0, $iterations = -1);
$reactor->schedule(function() use ($reactor) { $reactor->stop(); }, $delay = RUN_FOR);
$reactor->run();
var_dump($count);



/*
stream_set_blocking(STDIN, FALSE);

$reactor = (new Amp\ReactorFactory)->select();

$reactor->onReadable(STDIN, function($stdin) {
    echo "STDIN is readable!\n";
    fread($stdin, 8192); // <-- if we don't clear the stream's buffered data it'll be readable forever
});

$reactor->onReadable(STDIN, function($stdin) {
    echo "STDIN is readable for multiple callbacks!!!11\n";
});

$reactor->once(function() use ($reactor) {
    $reactor->stop();
}, $delay = 10);

echo "Type something into STDIN. Program will terminate in ten seconds.\n";

$reactor->run();
*/
