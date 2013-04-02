<?php

namespace Amp\Async;

class Frame {
    
    const OP_DATA  = 0x00;
    const OP_CLOSE = 0x0A;
    const OP_PING  = 0x0B;
    
    private $fin;
    private $rsv;
    private $opcode;
    private $payload;
    private $length;
    
    function __construct($fin, $rsv, $opcode, $payload) {
        $this->fin = $fin;
        $this->rsv = $rsv;
        $this->opcode = $opcode;
        $this->payload = (string) $payload;
        $this->length = strlen($payload);
    }
    
    function isFin() {
        return $this->fin;
    }
    
    function getRsv() {
        return $this->rsv;
    }
    
    function getOpcode() {
        return $this->opcode;
    }
    
    function getPayload() {
        return $this->payload;
    }
    
    function getLength() {
        return $this->length;
    }
    
    function __toString() {
        $firstByte = 0x00;
        $firstByte |= ((int) $this->fin) << 7;
        $firstByte |= $this->rsv << 4;
        $firstByte |= $this->opcode;
        
        if ($this->length > 0xFFFF) {
            $secondByte = 0xFF;
            $lengthBody = pack('N', $this->length);
        } elseif ($this->length > 0xFE) {
            $secondByte = 0xFE;
            $lengthBody = pack('n', $this->length);
        } else {
            $secondByte = $this->length;
            $lengthBody = '';
        }
        
        return chr($firstByte) . chr($secondByte) . $lengthBody . $this->payload;
    }
    
}

