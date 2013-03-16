<?php

namespace Amp\Messaging;

/*
    AMP FRAMING SPEC

       0                   1                   2                   3
      0 1 2 3 4 5 6 7 8 9 0 1 2 3 4 5 6 7 8 9 0 1 2 3 4 5 6 7 8 9 0 1
     +-+-+-+-+-------+---------------+-------------------------------+
     |F|R|R|R| opcode|  Payload len  |    Extended payload length    |
     |I|S|S|S|  (4)  |      (8)      |           (16/32)             |
     |N|V|V|V|       |               |   (if payload len==254/255)   |
     | |1|2|3|       |               |                               |
     +-+-+-+-+-------+---------------+-------------------------------+
     |    Extended (len == 255)      |          Payload Data         |
     +-------------------------------+ - - - - - - - - - - - - - - - +
     :                     Payload Data continued ...                :
     + - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - +
     |                     Payload Data continued ...                |
     +---------------------------------------------------------------+


    FIN:  1 bit

      Indicates that this is the final fragment in a message.  The first
      fragment MAY also be the final fragment.

    RSV1, RSV2, RSV3:  1 bit each

      MUST be 0 unless an extension is negotiated that defines meanings
      for non-zero values.  If a nonzero value is received and none of
      the negotiated extensions defines the meaning of such a nonzero
      value, the receiving endpoint MUST _Fail the Pipe Connection_.

    Opcode:  4 bits

      Defines the interpretation of the "Payload data".  If an unknown
      opcode is received, the receiving endpoint MUST _Fail the
      Pipe Connection_.  The following values are defined.

      *  %x0 denotes a binary data frame

      *  %x1-9 are reserved for further non-control frames

      *  %xA denotes a connection close
      
      *  %xB denotes a non-fatal application error

      *  %xC-F are reserved for further control frames

    Payload length:  7 bits, 7+16 bits, or 7+32 bits

      The length of the "Payload data", in bytes: if 0-253, that is the
      payload length.  If 254, the following 2 bytes interpreted as a
      16-bit unsigned integer are the payload length.  If 255, the
      following 4 bytes interpreted as a 32-bit unsigned integer (the
      most significant bit MUST be 0) are the payload length.  Multibyte
      length quantities are expressed in network byte order.  Note that
      in all cases, the minimal number of bytes MUST be used to encode
      the length, for example, the length of a 252-byte-long string
      can't be encoded as the sequence 254, 0, 252.
*/

class Frame {
    
    const OP_DATA  = 0x00;
    const OP_CLOSE = 0x0A;
    const OP_ERROR = 0x0B;
    
    private $fin;
    private $rsv;
    private $opcode;
    private $length;
    private $payload;
    
    /**
     * $length is explicitly required to allow for stream resource payload bodies
     */
    function __construct($fin, $rsv, $opcode, $payload, $length) {
        $this->fin = $fin;
        $this->rsv = $rsv;
        $this->opcode = $opcode;
        $this->payload = $payload;
        $this->length = $length;
    }
    
    function isFin() {
        return $this->fin;
    }
    
    function getRsv() {
        return $this->rsv;
    }
    
    function hasRsv1() {
        return (bool) $this->rsv & 0b001;
    }

    function hasRsv2() {
        return (bool) $this->rsv & 0b010;
    }

    function hasRsv3() {
        return (bool) $this->rsv & 0b100;
    }
    
    function getOpcode() {
        return $this->opcode;
    }
    
    function getLength() {
        return $this->length;
    }
    
    function getPayload() {
        return $this->payload;
    }
    
    function getHeader() {
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
        
        return chr($firstByte) . chr($secondByte) . $lengthBody;
    }
    
}

