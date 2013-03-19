<?php

namespace Amp\Async\Processes;

use Amp\Async\ProtocolException,
    Amp\Async\ProcedureException,
    Amp\Async\Processes\Io\Frame,
    Amp\Async\Processes\Io\Message,
    Amp\Async\Processes\Io\FrameParser,
    Amp\Async\Processes\Io\FrameWriter;

class WorkerService {
    
    private $parser;
    private $writer;
    private $frames = [];
    
    function __construct(FrameParser $parser, FrameWriter $writer) {
        $this->parser = $parser;
        $this->writer = $writer;
    }
    
    function onReadable() {
        if ($frame = $this->parser->parse()) {
            $this->frames[] = $frame;
        }
        
        if ($frame && $frame->isFin()) {
            $msg = new Message($this->frames);
            $this->frames = [];
            
            try {
                $result = $this->onMessage($msg);
                $opcode = Frame::OP_DATA;
            } catch (\Exception $e) {
                $result = $e;
                $opcode = Frame::OP_ERROR;
            }
            
            $length = strlen($result);
            $frame = new Frame($fin = 1, $rsv = 0, $opcode, $result, $length);
            
            try {
                $this->writer->write($frame);
            } catch (\Exception $e) {
                die;
            }
        }
    }
    
    private function onMessage(Message $msg) {
        $payload = $msg->getPayload();
        
        list($procedure, $args) = unserialize($payload);
        
        try {
            $result = call_user_func_array($procedure, $args);
        } catch (\Exception $e) {
            throw new ProcedureException(
                "Uncaught exception encountered invoking {$procedure}; task payload: {$payload}",
                NULL,
                $e
            );
        }
        
        return serialize($result);
    }
    
}

