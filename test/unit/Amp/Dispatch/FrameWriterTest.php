<?php

use Amp\Dispatch\Frame,
    Amp\Dispatch\FrameWriter;

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
        
        $expected = $frame->__toString();
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
        
        $expected = $frame1->__toString() . $frame2->__toString();
        rewind($outputStream);
        $actual = stream_get_contents($outputStream);
        
        $this->assertEquals($expected, $actual);
    }
    
    function testWriteAll() {
        $outputStream = fopen('php://memory', 'r+');
        $writer = new FrameWriter($outputStream);
        $writer->setGranularity(1);
        
        $data = str_repeat('x', 1024);
        $writer->writeAll($data);
        rewind($outputStream);
        $this->assertEquals($data, stream_get_contents($outputStream));
    }
    
    /**
     * @expectedException Amp\Dispatch\ResourceException
     */
    function testWriteThrowsExceptionIfOutputStreamIsNotResource() {
        $str = 'test';
        
        $outputStream = new StdClass;
        $writer = new FrameWriter($outputStream);
        $writer->write($str);
    }
    
}
    
