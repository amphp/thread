<?php

namespace Amp\Async;

class WorkerService {
    
    private $parser;
    private $writer;
    private $buffer;
    
    function __construct(FrameParser $parser, FrameWriter $writer) {
        $this->parser = $parser;
        $this->writer = $writer;
        $this->buffer = '';
    }
    
    function onReadable() {
        while ($frameArr = $this->parser->parse()) {
            list($isFin, $rsv, $opcode, $payload) = $frameArr;
            
            $this->buffer .= $payload;
            
            if (!$isFin) {
                return;
            }
            
            $callId    = substr($this->buffer, 0, 4);
            $procLen   = ord($this->buffer[4]);
            $procedure = substr($this->buffer, 5, $procLen);
            $workload  = substr($this->buffer, $procLen + 5);
            $workload  = unserialize($workload);
            
            $this->buffer = '';
        
            try {
                $rsv = 0b011;
                $result = serialize(call_user_func_array($procedure, $workload));
            } catch (\Exception $e) {
                $rsv = 0b111;
                $result = $e->__toString();
            }
            
            $payload = $callId . $result;
            $frame = new Frame($fin = 1, $rsv, $opcode = Frame::OP_DATA, $payload);
            
            if (!$this->writer->write($frame)) {
                while (!$this->writer->write());
            }
        }
    }
    
}

