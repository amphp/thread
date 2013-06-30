<?php

require dirname(__DIR__) . '/autoload.php';

$reactor = (new Amp\ReactorFactory)->select();

stream_set_blocking(STDIN, FALSE);

/**
 * Echo back the data each time there is readable data on STDIN
 */
$reactor->onReadable(STDIN, function($stdin, $trigger) {
    while ($line = fgets($stdin)) {
        echo "--- $line";
    }
});


/**
 * Stop the program after N seconds
 */
define('SECONDS_TO_RUN', 10);

$count = SECONDS_TO_RUN;
$reactor->schedule(function() use ($reactor, &$count) {
    if (--$count) {
        echo "- countdown: $count\n";
    } else {
        $reactor->stop();
    }
}, $delay = 1);

echo "Each input line will be echoed back to you for the next ", SECONDS_TO_RUN, " seconds ...\n\n";

$reactor->run();

