<?php

use Amp\ReactorFactory,
    Amp\Async\WorkerService,
    Amp\Async\FrameParser,
    Amp\Async\FrameWriter,
    Amp\Async\WorkerException;

date_default_timezone_set(ini_get('date.timezone') ?: 'UTC');
error_reporting(E_ALL);

register_shutdown_function(function() {
    if (!$lastError = error_get_last()) {
        return;
    }
    
    $fatals = [
        E_ERROR           => 'Fatal Error',
        E_PARSE           => 'Parse Error',
        E_CORE_ERROR      => 'Core Error',
        E_CORE_WARNING    => 'Core Warning',
        E_COMPILE_ERROR   => 'Compile Error',
        E_COMPILE_WARNING => 'Compile Warning'
    ];
    
    if (!isset($fatals[$lastError['type']])) {
        return;
    }
    
    $msg = $fatals[$lastError['type']] . ': ' . $lastError['message'] . ' in ';
    $msg.= $lastError['file'] . ' on line ' . $lastError['line'];
    
    $error  = (new WorkerException($msg))->__toString();
    $opcode = Frame::OP_ERROR;
    $frame  = new Frame($fin = 1, $rsv = 0, $opcode = Frame::OP_ERROR, $error);
    
    echo $frame->getHeader() . $frame->getPayload();
});




require dirname(__DIR__) . '/autoload.php';



$parser = new FrameParser(STDIN);
$writer = new FrameWriter(STDOUT);
$worker = new WorkerService($parser, $writer);

$reactor = (new ReactorFactory)->select();
$reactor->onReadable(STDIN, [$worker, 'onReadable']);

// Include userland functions from the specified file. Otherwise, only native functions are available.
if (!empty($argv[1])) {
    @include($argv[1]);
}

$reactor->run();

