<?php

use Amp\Frame,
    Amp\FrameParser;

class FrameParserTest extends PHPUnit_Framework_TestCase {
    
    /**
     * @dataProvider provideFrameExpectations
     */
    function testParse($opcode, $payload, $expectedValues) {
        $frame = new Frame($opcode, $payload);
        $parser = new FrameParser;
        $parser->bufferData($frame);
        
        $parsedFrame = $parser->parse();
        $this->assertInstanceOf('Amp\Frame', $parsedFrame);
        $this->assertSame($frame->getOpcode(), $parsedFrame->getOpcode());
        $this->assertSame($frame->getLength(), $parsedFrame->getLength());
        $this->assertSame($frame->getHeader(), $parsedFrame->getHeader());
        $this->assertSame($frame->getPayload(), $parsedFrame->getPayload());
    }
    
    /**
     * @dataProvider provideFrameExpectations
     */
    function testIncrementalParse($opcode, $payload, $expectedValues) {
        $frame = new Frame($opcode, $payload);
        $parser = new FrameParser;
        
        $frameStr = $frame->__toString();
        for ($i=0; $i<strlen($frameStr); $i++) {
            $parser->bufferData($frameStr[$i]);
            $parsedFrame = $parser->parse();
        }
        
        $this->assertInstanceOf('Amp\Frame', $parsedFrame);
        $this->assertSame($frame->getOpcode(), $parsedFrame->getOpcode());
        $this->assertSame($frame->getLength(), $parsedFrame->getLength());
        $this->assertSame($frame->getHeader(), $parsedFrame->getHeader());
        $this->assertSame($frame->getPayload(), $parsedFrame->getPayload());
    }
    
    function provideFrameExpectations() {
        $return = array();
        
        // 0 -------------------------------------------------------------------------------------->
        
        $opcode = Frame::OP_DATA_MORE;
        $payload = 'test';
        $header = $opcode . chr(strlen($payload));
        $return[] = array($opcode, $payload, $expectedValues = array(
            'opcode' => $opcode,
            'payload' => $payload,
            'header' => $header,
            'length' => strlen($payload)
        ));
        
        // 1 -------------------------------------------------------------------------------------->
        
        $opcode = Frame::OP_DATA_FIN;
        $payload = str_repeat('x', 255);
        $header = $opcode . chr(254) . pack('n', strlen($payload));
        $return[] = array($opcode, $payload, $expectedValues = array(
            'opcode' => $opcode,
            'payload' => $payload,
            'header' => $header,
            'length' => strlen($payload)
        ));
        
        // 2 -------------------------------------------------------------------------------------->
        
        $opcode = Frame::OP_DATA_FIN;
        $payload = str_repeat('x', 254);
        $header = $opcode . chr(254) . pack('n', strlen($payload));
        $return[] = array($opcode, $payload, $expectedValues = array(
            'opcode' => $opcode,
            'payload' => $payload,
            'header' => $header,
            'length' => strlen($payload)
        ));
        
        // 3 -------------------------------------------------------------------------------------->
        
        $opcode = Frame::OP_DATA_FIN;
        $payload = str_repeat('x', 65535);
        $header = $opcode . chr(254) . pack('n', strlen($payload));
        $return[] = array($opcode, $payload, $expectedValues = array(
            'opcode' => $opcode,
            'payload' => $payload,
            'header' => $header,
            'length' => strlen($payload)
        ));
        
        // 4 -------------------------------------------------------------------------------------->
        
        $opcode = Frame::OP_DATA_FIN;
        $payload = str_repeat('x', 65536);
        $header = $opcode . chr(255) . pack('N', strlen($payload));
        $return[] = array($opcode, $payload, $expectedValues = array(
            'opcode' => $opcode,
            'payload' => $payload,
            'header' => $header,
            'length' => strlen($payload)
        ));
        
        // x -------------------------------------------------------------------------------------->
        
        return $return;
    }
    
}

