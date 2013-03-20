<?php

require dirname(__DIR__) . '/autoload.php';

@date_default_timezone_set(date_default_timezone_get());

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
 * Stop the program after 15 seconds
 */
$reactor->once($stopAfter = 15, function() use ($reactor) {
   $reactor->stop();
});

echo "Each input line will be echoed back to you for the next 15 seconds ...\n\n";

$reactor->run();

