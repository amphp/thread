<?php

use Amp\ReactorFactory,
    Amp\Async\WorkerService,
    Amp\Async\FrameParser,
    Amp\Async\FrameWriter;

date_default_timezone_set(ini_get('date.timezone') ?: 'UTC');

error_reporting(E_ALL);

require dirname(__DIR__) . '/autoload.php';

// Include userland functions from the specified file. Otherwise, only native functions are available.
if (!empty($argv[1])) {
    include($argv[1]);
}

$parser = new FrameParser(STDIN);
$writer = new FrameWriter(STDOUT);
$worker = new WorkerService($parser, $writer);

$reactor = (new ReactorFactory)->select();
$reactor->onReadable(STDIN, [$worker, 'onReadable']);
$reactor->run();

