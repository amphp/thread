<?php

use Amp\Frame;

class FrameTest extends PHPUnit_Framework_TestCase {
    
    /**
     * @dataProvider provideFrameExpectations
     */
    function testGetPayload($opcode, $payload, $expectedValues) {
        $frame = new Frame($opcode, $payload);
        $this->assertEquals($expectedValues['payload'], $frame->getPayload());
    }
    
    /**
     * @dataProvider provideFrameExpectations
     */
    function testGetLength($opcode, $payload, $expectedValues) {
        $frame = new Frame($opcode, $payload);
        $this->assertEquals($expectedValues['length'], $frame->getLength());
    }
    
    /**
     * @dataProvider provideFrameExpectations
     */
    function testGetOpcode($opcode, $payload, $expectedValues) {
        $frame = new Frame($opcode, $payload);
        $this->assertEquals($expectedValues['opcode'], $frame->getOpcode());
    }
    
    /**
     * @dataProvider provideFrameExpectations
     */
    function testGetHeader($opcode, $payload, $expectedValues) {
        $frame = new Frame($opcode, $payload);
        $this->assertEquals($expectedValues['header'], $frame->getHeader());
    }
    
    /**
     * @dataProvider provideFrameExpectations
     */
    function testToString($opcode, $payload, $expectedValues) {
        $frame = new Frame($opcode, $payload);
        $this->assertEquals($expectedValues['header'] . $expectedValues['payload'], $frame->__toString());
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

