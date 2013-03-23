<?php

use Amp\Async\Frame,
    Amp\Async\FrameWriter;

class FrameWriterTest extends PHPUnit_Framework_TestCase {
    
    function testMultiStageBufferedPayloadWrite() {
        $frame = new Frame($fin = 1, $rsv = 0, $opcode = Frame::OP_DATA, $data = 42, $length = 2);
        
        $outputStream = fopen('php://memory', 'r+');
        $writer = new FrameWriter($outputStream);
        $writer->setGranularity(1);
        
        $writer->write($frame);
        
        while (!$writer->write()) {
            continue;
        }
        
        rewind($outputStream);
        
        $expected = $frame->getHeader() . $frame->getPayload();
        $actual = stream_get_contents($outputStream);
        
        $this->assertEquals($expected, $actual);
    }
    
    function testQueuedFrameWrite() {
        $frame1 = new Frame($fin = 1, $rsv = 0, $opcode = Frame::OP_DATA, $data = 42, $length = 2);
        $frame2 = new Frame($fin = 1, $rsv = 0, $opcode = Frame::OP_DATA, $data = 23, $length = 2);
        
        $outputStream = fopen('php://memory', 'r+');
        $writer = new FrameWriter($outputStream);
        $writer->setGranularity(1);
        
        $writer->write($frame1);
        $writer->write($frame2);
        
        while (!$writer->write()) {
            continue;
        }
        
        $expected = $frame1->getHeader() . $frame1->getPayload() . $frame2->getHeader() . $frame2->getPayload();
        rewind($outputStream);
        $actual = stream_get_contents($outputStream);
        
        $this->assertEquals($expected, $actual);
    }
    
}
    
