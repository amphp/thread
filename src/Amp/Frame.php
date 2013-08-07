<?php

namespace Amp;

class Frame {

    const OP_DATA_MORE = 0;
    const OP_DATA_FIN = 1;
    const OP_CLOSE = 2;
    const OP_PING = 3;
    const OP_PONG = 4;

    private $opcode;
    private $payload;
    private $length;

    function __construct($opcode, $payload) {
        $this->opcode = $opcode;
        $this->payload = $payload;
        $this->length = strlen($payload);
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

    function getHeader() {
        if ($this->length > 0xFFFF) {
            $secondByte = 0xFF;
            $lengthBody = pack('N', $this->length);
        } elseif ($this->length < 0xFE) {
            $secondByte = $this->length;
            $lengthBody = '';
        } else {
            $secondByte = 0xFE;
            $lengthBody = pack('n', $this->length);
        }

        return $this->opcode . chr($secondByte) . $lengthBody;
    }

    function __toString() {
        return $this->getHeader() . $this->payload;
    }

}
