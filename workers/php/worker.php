<?php

use Amp\ReactorFactory,
    Amp\Dispatch\Process\FrameParser,
    Amp\Dispatch\Process\FrameWriter,
    Amp\Dispatch\Process\WorkerService;

date_default_timezone_set(ini_get('date.timezone') ?: 'UTC');

require dirname(dirname(__DIR__)) . '/autoload.php';

/**
 * Report ALL errors. Without this setting debugging problems in child processes can be a NIGHTMARE.
 * Any errors triggered by this process are written to the main process's STDERR stream.
 */
error_reporting(E_ALL);
ini_set('display_errors', 'stderr');

/**
 * Include userland code/functions from the specified file.
 */
if (!empty($argv[1])) {
    include($argv[1]);
}

stream_set_blocking(STDIN, FALSE);
stream_set_blocking(STDOUT, FALSE);

$parser = new FrameParser(STDIN);
$writer = new FrameWriter(STDOUT);
$worker = new WorkerService($parser, $writer);

$reactor = (new ReactorFactory)->select();
$reactor->onReadable(STDIN, [$worker, 'onReadable']);

$reactor->run();

