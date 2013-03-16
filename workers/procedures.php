<?php

use Amp\Reactor,
    Amp\ReactorFactory,
    Amp\Messaging\FrameParser,
    Amp\Messaging\FrameWriter,
    Amp\Messaging\Message,
    Amp\Messaging\Frame;

date_default_timezone_set('GMT');

require dirname(__DIR__) . '/autoload.php';

class ProtocolException extends \RuntimeException {}
class ProcedureException extends \RuntimeException {}

function getLastJsonError() {
    switch (json_last_error()) {
        case JSON_ERROR_DEPTH:
            $errMsg = 'The maximum stack depth has been exceeded';
            break;
        case JSON_ERROR_STATE_MISMATCH:
            $errMsg = 'Invalid or malformed JSON';
            break;
        case JSON_ERROR_CTRL_CHAR:
            $errMsg = 'Control character error, possibly incorrectly encoded';
            break;
        case JSON_ERROR_SYNTAX:
            $errMsg = 'Syntax error';
            break;
        case JSON_ERROR_UTF8:
            $errMsg = 'Malformed UTF-8 characters, possibly incorrectly encoded';
            break;
        default:
            $errMsg = 'Unknown error';
    }
    
    return $errMsg;
}

function main(Message $msg) {
    $payload = $msg->getPayload();
    $args = json_decode($payload);
    
    if ($args === NULL) {
        throw new ProtocolException(
            'JSON error: ' . getLastJsonError()
        );
    } elseif (empty($args[0])) {
        throw new ProtocolException(
            'No procedure specified'
        );
    } elseif (!is_callable($args[0]) || $args[0] == 'main') {
        throw new ProtocolException(
            'Specified procedure not available: ' . $args[0]
        );
    } else {
        $procedure = array_shift($args);
        $args = array_shift($args);
        
        try {
            $result = call_user_func_array($procedure, $args);
        } catch (\Exception $e) {
            throw new ProcedureException(
                $payload,
                NULL,
                $e
            );
        }
        
        if (FALSE === ($result = json_encode($result))) {
            throw new ProtocolException(
                'Failed encoding procedure result for transport; JSON error: ' . getLastJsonError()
            );
        }
        
        return $result;
    }
}

if (!empty($argv[1])) {
    @include($argv[1]);
}

$reactor = (new ReactorFactory)->select();
$frameParser = new FrameParser(STDIN);
$frameWriter = new FrameWriter(STDOUT);
$frames = [];

$reactor->onReadable(STDIN, function() use ($frameParser, $frameWriter, &$frames) {
    if (!$frame = $frameParser->parse()) {
        return;
    }
    
    $frames[] = $frame;
    
    if (!$frame->isFin()) {
        return;
    }
    
    $msg = new Message($frames);
    $frames = [];
    
    try {
        $result = main($msg);
        $opcode = Frame::OP_DATA;
    } catch (Exception $e) {
        $result = $e;
        $opcode = Frame::OP_ERROR;
    }
    
    $length = strlen($result);
    $frame = new Frame($fin = 1, $rsv = 0, $opcode, $result, $length);
    
    try {
        $frameWriter->write($frame);
    } catch (Exception $e) {
        die;
    }
});

$reactor->run();

