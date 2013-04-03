<?php

use Amp\Async\WorkerService,
    Amp\Async\FrameParser,
    Amp\Async\FrameWriter,
    Amp\Async\Frame,
    Amp\Async\Dispatcher,
    Amp\Async\ResourceException;

class WorkerServiceTest extends PHPUnit_Framework_TestCase {
    
    function testOnReadable() {
        
        $callId = pack('N', 123456);
        
        $len = 256;
        $workload = str_repeat('.', $len);
        $procedure = 'strlen';
        $procLen = chr(strlen($procedure));
        $payload = $callId . $procLen . $procedure . $workload;
        $callFrame = new Frame($fin = 1, $rsv = 1, $opcode = Frame::OP_DATA, $payload);
        
        $inputStream = fopen('php://memory', 'r+');
        fwrite($inputStream, $callFrame);
        rewind($inputStream);
        
        $outputStream = fopen('php://memory', 'r+');
        $parser = new FrameParser($inputStream);
        $writer = new FrameWriter($outputStream);
        
        $workerService = new WorkerService($parser, $writer);
        $workerService->onReadable();
        
        rewind($outputStream);
        
        $endParser = new FrameParser($outputStream);
        $frameArr = $endParser->parse();
        list($isFin, $rsv, $opcode, $payload) = $frameArr;
        
        $this->assertEquals($callId, substr($payload, 0, 4));
        $this->assertEquals($len, substr($payload, 4));
    }
    
    function testOnReadableWithStreamingMultiFrameResult() {
        
        $callId = pack('N', 123456);
        
        $procedure = 'streamWorkerResultFunc';
        $procLen = chr(strlen($procedure));
        $payload = $callId . $procLen . $procedure;
        $callFrame = new Frame($fin = 1, $rsv = 1, $opcode = Frame::OP_DATA, $payload);
        
        $inputStream = fopen('php://memory', 'r+');
        fwrite($inputStream, $callFrame);
        rewind($inputStream);
        
        $outputStream = fopen('php://memory', 'r+');
        $parser = new FrameParser($inputStream);
        $writer = new FrameWriter($outputStream);
        
        $workerService = new WorkerService($parser, $writer);
        $workerService->onReadable();
        
        rewind($outputStream);
        
        $endParser = new FrameParser($outputStream);
        
        $frameArr = $endParser->parse();
        list($isFin, $rsv, $opcode, $payload) = $frameArr;
        $this->assertEquals($isFin, 0);
        $this->assertEquals($callId, substr($payload, 0, 4));
        $this->assertEquals('chunk1', substr($payload, 4));
        
        $frameArr = $endParser->parse();
        list($isFin, $rsv, $opcode, $payload) = $frameArr;
        $this->assertEquals($isFin, 0);
        $this->assertEquals($callId, substr($payload, 0, 4));
        $this->assertEquals('chunk2', substr($payload, 4));
        
        $frameArr = $endParser->parse();
        list($isFin, $rsv, $opcode, $payload) = $frameArr;
        $this->assertEquals($isFin, 1);
        $this->assertEquals($callId, substr($payload, 0, 4));
        $this->assertEquals('chunk3', substr($payload, 4));
    }
    
    function testOnReadableReturnsCallErrorOnInvalidProcedureReturnType() {
        
        $callId = pack('N', 123456);
        
        $procedure = 'invalidReturnTestFunc';
        $procLen = chr(strlen($procedure));
        $payload = $callId . $procLen . $procedure;
        $callFrame = new Frame($fin = 1, $rsv = 1, $opcode = Frame::OP_DATA, $payload);
        
        $inputStream = fopen('php://memory', 'r+');
        fwrite($inputStream, $callFrame);
        rewind($inputStream);
        
        $outputStream = fopen('php://memory', 'r+');
        $parser = new FrameParser($inputStream);
        $writer = new FrameWriter($outputStream);
        
        $workerService = new WorkerService($parser, $writer);
        $workerService->onReadable();
        
        rewind($outputStream);
        
        $endParser = new FrameParser($outputStream);
        $frameArr = $endParser->parse();
        list($isFin, $rsv, $opcode, $payload) = $frameArr;
        
        $this->assertEquals($callId, substr($payload, 0, 4));
        $this->assertTrue((bool) ($rsv & Dispatcher::CALL_ERROR));
    }
    
    /**
     * @expectedException Amp\Async\ResourceException
     */
    function testOnReadableThrowsExceptionOnBrokenWritePipe() {
        $callId = pack('N', 123456);
        
        $procedure = 'strlen';
        $workload = 'test';
        $procLen = chr(strlen($procedure));
        $payload = $callId . $procLen . $procedure . $workload;
        $callFrame = new Frame($fin = 1, $rsv = 1, $opcode = Frame::OP_DATA, $payload);
        
        $inputStream = fopen('php://memory', 'r+');
        fwrite($inputStream, $callFrame);
        rewind($inputStream);
        
        $parser = new FrameParser($inputStream);
        $writer = $this->getMock('Amp\\Async\\FrameWriter', NULL, ['writeAll']);
        $writer->expects($this->any())
               ->method('writeAll')
               ->will($this->throwException(new ResourceException));
        
        $workerService = new WorkerService($parser, $writer);
        $workerService->onReadable();
    }
    
}

function invalidReturnTestFunc() {
    return ["procedure return must be NULL, scalar or Iterator"];
}

function streamWorkerResultFunc() {
    return new MyTestIterator;
}

class MyTestIterator implements Iterator {
    
    private $position = 0;
    private $parts = ['chunk1', 'chunk2', 'chunk3'];

    function rewind() {
        $this->position = 0;
    }

    function current() {
        return $this->parts[$this->position];
    }

    function key() {
        return $this->position;
    }

    function next() {
        $this->position++;
    }

    function valid() {
        return isset($this->parts[$this->position]);
    }
    
}
