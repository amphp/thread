<?php

require dirname(__DIR__) . '/autoload.php';

date_default_timezone_set('GMT');
stream_set_blocking(STDIN, FALSE);

$reactor = (new Amp\ReactorFactory)->select();

/**
 * Echo back the data each time there is readable data on STDIN
 */
$reactor->onReadable(STDIN, function($stdin, $trigger) {
    while ($line = fgets($stdin)) {
        echo "--- $line";
    }
});

/**
 * Output "Zanzibar!" every three seconds
 */
$repeatInterval = 3 * $reactor->getResolution();
$reactor->repeat($repeatInterval, function() {
    echo "Zanzibar!\n";
});

/**
 * Stop the program after 15 seconds
 */
$stopAfter = 15 * $reactor->getResolution();
$reactor->once($stopAfter, function() use ($reactor) {
   $reactor->stop();
});

$reactor->run();

