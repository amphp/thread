<?php

use Amp\Async\Processes\Io\Frame,
    Amp\Async\Processes\Io\FrameParser;

class FrameParserTest extends PHPUnit_Framework_TestCase {
    
    function provideParseExpectations() {
        $frames = [];
        
        // 0 -------------------------------------------------------------------------------------->
        
        $payload = 'payload val';
        $length = strlen($payload);
        $frame = new Frame($fin=1, $rsv=0, $op=Frame::OP_DATA, $payload, $length);
        
        $frames[] = [$frame];
        
        // x -------------------------------------------------------------------------------------->
        
        return $frames;
    }
    
    /**
     * @dataProvider provideParseExpectations
     */
    function testParse($frame) {
        $inputStream = fopen('php://memory', 'r+');
        fwrite($inputStream, $frame->getHeader() . $frame->getPayload());
        rewind($inputStream);
        
        $frameParser = new FrameParser($inputStream);
        $parseResult = $frameParser->parse();
        
        $this->assertInstanceOf('Amp\\Async\Processes\\Io\\Frame', $parseResult);
        $this->assertEquals($frame->getPayload(), $parseResult->getPayload());
        $this->assertEquals($frame->getLength(), $parseResult->getLength());
        $this->assertEquals($frame->getOpcode(), $parseResult->getOpcode());
        $this->assertEquals($frame->isFin(), $parseResult->isFin());
        $this->assertEquals($frame->getRsv(), $parseResult->getRsv());
    }
    
    function provideMultiParseExpectations() {
        $frameArrays = [];
        
        // 0 -------------------------------------------------------------------------------------->
        
        $frames = [];
        
        $payload = "payload 1\n";
        $length = strlen($payload);
        $frames[] = new Frame($fin=0, $rsv=0, $op=Frame::OP_DATA, $payload, $length);
        
        $payload = "payload 2\n";
        $length = strlen($payload);
        $frames[] = new Frame($fin=0, $rsv=0, $op=Frame::OP_DATA, $payload, $length);
        
        $payload = "payload 3\n";
        $length = strlen($payload);
        $frames[] = new Frame($fin=0, $rsv=0, $op=Frame::OP_DATA, $payload, $length);
        
        $frames[] = new Frame($fin=1, $rsv=0, $op=Frame::OP_DATA, '1', 1);
        
        $frameArrays[] = [$frames];
        
        // x -------------------------------------------------------------------------------------->
        
        return $frameArrays;
    }
    
    
    /**
     * @dataProvider provideMultiParseExpectations
     */
    function testMultiParse($frameArray) {
        
        $expectedResult = '';
        $inputStream = fopen('php://memory', 'r+');
        foreach ($frameArray as $frame) {
            $payload = $frame->getPayload();
            $expectedResult .= $payload;
            fwrite($inputStream, $frame->getHeader() . $payload);
        }
        rewind($inputStream);
        
        $frameParser = new FrameParser($inputStream);
        
        $actualResult = '';
        while (TRUE) {
            $frame = $frameParser->parse();
            $actualResult .= $frame->getPayload();
            if ($frame->isFin()) {
                break;
            }
        }

        $this->assertEquals($expectedResult, $actualResult);
    }
    
}




























