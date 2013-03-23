<?php

use Amp\Async\Frame;

class FrameTest extends PHPUnit_Framework_TestCase {
    
    function testGetPayload() {
        $frame = new Frame($fin = 1, $rsv = 0, $opcode = Frame::OP_DATA, $payload = 'test');
        $this->assertEquals('test', $frame->getPayload());
    }
    
    function testGetLength() {
        $frame = new Frame($fin = 1, $rsv = 0, $opcode = Frame::OP_DATA, $payload = 'test');
        $this->assertEquals(4, $frame->getLength());
    }
    
    function testIsFin() {
        $frame = new Frame($fin = 1, $rsv = 0, $opcode = Frame::OP_DATA, $payload = 'test');
        $this->assertEquals(TRUE, $frame->isFin());
    }
    
    function testGetRsv() {
        $frame = new Frame($fin = 1, $rsv = 0, $opcode = Frame::OP_DATA, $payload = 'test');
        $this->assertEquals(0, $frame->getRsv());
    }
    
    function testGetOpcode() {
        $frame = new Frame($fin = 1, $rsv = 0, $opcode = Frame::OP_CLOSE, $payload = '');
        $this->assertEquals(Frame::OP_CLOSE, $frame->getOpcode());
    }
    
    
    
}

