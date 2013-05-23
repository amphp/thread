<?php

use Amp\MultiProcess\WorkerSession;

class WorkerSessionTest extends PHPUnit_Framework_TestCase {
    
    function testParse() {
        $worker = $this->getMock('Amp\MultiProcess\Worker', [], [NULL]);
        $parser = $this->getMock('Amp\MultiProcess\FrameParser', [], [NULL]);
        $writer = $this->getMock('Amp\MultiProcess\FrameWriter', [], [NULL]);
        
        $parser->expects($this->once())
               ->method('parse')
               ->will($this->returnValue(42));
        
        $ws = new WorkerSession($worker, $parser, $writer);
        $this->assertEquals(42, $ws->parse());
    }
    
    function testWrite() {
        $worker = $this->getMock('Amp\MultiProcess\Worker', [], [NULL]);
        $parser = $this->getMock('Amp\MultiProcess\FrameParser', [], [NULL]);
        $writer = $this->getMock('Amp\MultiProcess\FrameWriter', [], [NULL]);
        
        $writer->expects($this->once())
               ->method('write')
               ->with('towel')
               ->will($this->returnValue(42));
        
        $ws = new WorkerSession($worker, $parser, $writer);
        $this->assertEquals(42, $ws->write('towel'));
    }
    
    function testGetWritePipe() {
        $worker = $this->getMock('Amp\MultiProcess\Worker', [], [NULL]);
        $parser = $this->getMock('Amp\MultiProcess\FrameParser', [], [NULL]);
        $writer = $this->getMock('Amp\MultiProcess\FrameWriter', [], [NULL]);
        
        $worker->expects($this->once())
               ->method('getWritePipe')
               ->will($this->returnValue(42));
        
        $ws = new WorkerSession($worker, $parser, $writer);
        $this->assertEquals(42, $ws->getWritePipe());
    }
    
    function testGetReadPipe() {
        $worker = $this->getMock('Amp\MultiProcess\Worker', [], [NULL]);
        $parser = $this->getMock('Amp\MultiProcess\FrameParser', [], [NULL]);
        $writer = $this->getMock('Amp\MultiProcess\FrameWriter', [], [NULL]);
        
        $worker->expects($this->once())
               ->method('getReadPipe')
               ->will($this->returnValue(42));
        
        $ws = new WorkerSession($worker, $parser, $writer);
        $this->assertEquals(42, $ws->getReadPipe());
    }
    
    function testGetPipes() {
        $worker = $this->getMock('Amp\MultiProcess\Worker', [], [NULL]);
        $parser = $this->getMock('Amp\MultiProcess\FrameParser', [], [NULL]);
        $writer = $this->getMock('Amp\MultiProcess\FrameWriter', [], [NULL]);
        
        $worker->expects($this->once())
               ->method('getPipes')
               ->will($this->returnValue(42));
        
        $ws = new WorkerSession($worker, $parser, $writer);
        $this->assertEquals(42, $ws->getPipes());
    }

}

