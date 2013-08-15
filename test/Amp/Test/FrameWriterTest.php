<?php

namespace Amp\Test;

use Amp\Frame,
    Amp\FrameWriter;

class FrameWriterTest extends \PHPUnit_Framework_TestCase {
    
    function testMultiStageBufferedPayloadWrite() {
        $frame = new Frame(Frame::OP_DATA_FIN, $data = 42);
        
        $outputStream = fopen('php://memory', 'r+');
        $writer = new FrameWriter($outputStream);
        $writer->enqueueFrame($frame);
        
        while (!$writer->write()) {
            continue;
        }
        
        rewind($outputStream);
        
        $expected = $frame->__toString();
        $actual = stream_get_contents($outputStream);
        
        $this->assertEquals($expected, $actual);
    }
    
    function testQueuedFrameWrite() {
        $frame1 = new Frame(Frame::OP_DATA_FIN, $data = 42);
        $frame2 = new Frame(Frame::OP_DATA_FIN, $data = 23);
        
        $outputStream = fopen('php://memory', 'r+');
        $writer = new FrameWriter($outputStream);
        $writer->enqueueFrame($frame1);
        $writer->enqueueFrame($frame2);
        
        while (!$writer->write()) {
            continue;
        }
        
        $expected = $frame1->__toString() . $frame2->__toString();
        rewind($outputStream);
        $actual = stream_get_contents($outputStream);
        
        $this->assertEquals($expected, $actual);
    }
    
    /**
     * @expectedException Amp\ResourceException
     */
    function testWriteThrowsExceptionIfOutputStreamIsNotResource() {
        $outputStream = new \stdClass();
        $writer = new FrameWriter($outputStream);
        
        $frame = new Frame(Frame::OP_DATA_FIN, $data = 42);
        $writer->enqueueFrame($frame);
        
        $writer->write();
    }
    
}
    
