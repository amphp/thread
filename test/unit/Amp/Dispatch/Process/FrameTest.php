<?php

use Amp\Dispatch\Process\Frame;

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
    
    function testToString() {
        $frame = new Frame($fin = 1, $rsv = 0, $opcode = Frame::OP_DATA, $payload = 'test');
        
        $str = $frame->__toString();
        
        $this->assertEquals(4, ord($str[1]));
        $this->assertEquals('test', substr($str, 2, 4));
        
        $payload = str_repeat('x', 300);
        $frame = new Frame($fin = 1, $rsv = 0, $opcode = Frame::OP_DATA, $payload);
        $str = $frame->__toString();
        $this->assertEquals(254, ord($str[1]));
        
        $payload = str_repeat('x', 70000);
        $frame = new Frame($fin = 1, $rsv = 0, $opcode = Frame::OP_DATA, $payload);
        $str = $frame->__toString();
        $this->assertEquals(255, ord($str[1]));
        
    }
    
}

