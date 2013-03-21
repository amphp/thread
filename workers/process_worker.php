<?php

use Amp\ReactorFactory,
    Amp\Async\Processes\WorkerService,
    Amp\Async\Processes\FrameParser,
    Amp\Async\Processes\FrameWriter;

date_default_timezone_set(ini_get('date.timezone') ?: 'UTC');

require dirname(__DIR__) . '/autoload.php';

if (!empty($argv[1])) {
    @include($argv[1]);
}

$parser = new FrameParser(STDIN);
$writer = new FrameWriter(STDOUT);
$worker = new WorkerService($parser, $writer);

$reactor = (new ReactorFactory)->select();
$reactor->onReadable(STDIN, [$worker, 'onReadable']);
$reactor->run();

