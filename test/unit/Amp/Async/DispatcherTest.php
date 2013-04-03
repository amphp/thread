<?php

use Amp\Async\Dispatcher;

class DispatcherTest extends PHPUnit_Framework_TestCase {
    
    function testSetCallTimeout() {
        $reactor = $this->getMock('Amp\Reactor');
        $dispatcher = new Dispatcher($reactor);
        $dispatcher->setCallTimeout(42);
    }
    
    function testSetGranularityDelegatesAssignment() {
        $reactor = $this->getMock('Amp\Reactor');
        $wsf = $this->getMock('Amp\Async\WorkerSessionFactory');
        $wsf->expects($this->once())
            ->method('setGranularity')
            ->with(42);
        
        $dispatcher = new Dispatcher($reactor, $wsf);
        $dispatcher->setGranularity(42);
    }
    
    function testNotifyOnPartialResult() {
        $reactor = $this->getMock('Amp\Reactor');
        $dispatcher = new Dispatcher($reactor);
        $dispatcher->notifyOnPartialResult(FALSE);
    }
}

