<?php

use Amp\Async\Processes\Frame,
    Amp\Async\Processes\FrameParser;

class FrameParserTest extends PHPUnit_Framework_TestCase {
    
    function provideParseExpectations() {
        $frames = [];
        
        // 0 -------------------------------------------------------------------------------------->
        
        $payload = 'payload val';
        $frame = new Frame($fin=1, $rsv=0, $op=Frame::OP_DATA, $payload);
        
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
        $frameArr = $frameParser->parse();
        
        list($isFin, $rsv, $opcode, $payload, $length) = $frameArr;
        
        $this->assertEquals($frame->isFin(), $isFin);
        $this->assertEquals($frame->getRsv(), $rsv);
        $this->assertEquals($frame->getOpcode(), $opcode);
        $this->assertEquals($frame->getPayload(), $payload);
        $this->assertEquals($frame->getLength(), $length);
    }
    
    function provideMultiParseExpectations() {
        $frameArrays = [];
        
        // 0 -------------------------------------------------------------------------------------->
        
        $frames = [];
        
        $payload = "payload 1\n";
        $frames[] = new Frame($fin=0, $rsv=0, $op=Frame::OP_DATA, $payload);
        
        $payload = "payload 2\n";
        $frames[] = new Frame($fin=0, $rsv=0, $op=Frame::OP_DATA, $payload);
        
        $payload = "payload 3\n";
        $frames[] = new Frame($fin=0, $rsv=0, $op=Frame::OP_DATA, $payload);
        
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
        $frameParser->setGranularity(1);
        
        $actualResult = '';
        while (TRUE) {
            if (!$frameArr = $frameParser->parse()) {
                continue;
            }
            
            list($isFin, $rsv, $opcode, $payload, $length) = $frameArr;
            
            $actualResult .= $payload;
            if ($isFin) {
                break;
            }
        }

        $this->assertEquals($expectedResult, $actualResult);
    }
    
}

