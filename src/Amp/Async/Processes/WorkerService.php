<?php

namespace Amp\Async\Processes;

use Amp\Async\ProcedureException,
    Amp\Async\Processes\Io\Frame,
    Amp\Async\Processes\Io\FrameParser,
    Amp\Async\Processes\Io\FrameWriter;

class WorkerService {
    
    private $parser;
    private $writer;
    private $buffer;
    
    function __construct(FrameParser $parser, FrameWriter $writer) {
        $this->parser = $parser;
        $this->writer = $writer;
    }
    
    function onReadable() {
        if (!$frame = $this->parser->parse()) {
            return;
        }
        
        $this->buffer .= $frame->getPayload();
        
        if ($frame->isFin()) {
            $payload = $this->buffer;
            $this->buffer = '';
            
            try {
                $result = $this->onMessage($payload);
                $opcode = Frame::OP_DATA;
            } catch (\Exception $e) {
                $result = $e;
                $opcode = Frame::OP_ERROR;
            }
            
            $length = strlen($result);
            $frame = new Frame($fin = 1, $rsv = 0, $opcode, $result);
            
            try {
                $this->writer->write($frame);
            } catch (\Exception $e) {
                die;
            }
        }
    }
    
    private function onMessage($payload) {
        list($procedure, $args) = unserialize($payload);
        
        try {
            $result = call_user_func_array($procedure, $args);
        } catch (\Exception $e) {
            throw new ProcedureException(
                "Uncaught exception encountered while invoking {$procedure}",
                NULL,
                $e
            );
        }
        
        return serialize($result);
    }
    
}

