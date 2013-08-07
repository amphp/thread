<?php

/**
 * AMP Job Server
 * 
 * php amp.php --listen"*:1337" --include"/path/to/user/functions.php"
 * 
 * Options:
 * ========
 * 
 * -l, --listen         (required) The listening address (e.g. 127.0.0.1:1337 or *:1337)
 * -i, --include        PHP userland include file
 * -b, --binary         Binary worker command
 * -w, --workers        Worker pool size (default: 4)
 * -u, --unserialized   Don't apply PHP serialization to call results
 * -d, --debug          Write debug info to STDOUT
 * -c, --colors         Use ANSI color codes in debug output
 * -h, --help           Display help screen
 * 
 * Any output generated during procedure invocations (via `echo` or otherwise) will be visible in
 * the console window (STDERR).
 */

require dirname(__DIR__) . '/autoload.php';

try {
    $bootstrapper = new Amp\JobServerBootstrap;
    if ($bootstrapper->loadOptions()) {
        $reactor = (new Alert\ReactorFactory)->select();
        $jobServer = $bootstrapper->createJobServer($reactor);
        $jobServer->start();
        $reactor->run();
    }
} catch (Exception $e) {
    echo $e->getMessage(), "\n";
}
