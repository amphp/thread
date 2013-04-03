<?php

use Amp\Async\Worker;

class WorkerTest extends PHPUnit_Framework_TestCase {

    function testWorkerSpawnsSubProcess() {
        $worker = new Worker(PHP_BINARY);
        $this->assertInstanceOf('Amp\\Async\\Worker', $worker);
        $this->assertFalse(getmypid() == $worker->getStatus()['pid']);
        
        return $worker;
    }
    
    /**
     * @depends testWorkerSpawnsSubProcess
     */
    function testGetWritePipe($worker) {
        $writePipe = $worker->getWritePipe();
        $this->assertEquals($writePipe, $worker->getPipes()[Worker::STDIN]);
        
        return $worker;
    }
    
    /**
     * @depends testGetWritePipe
     */
    function testGetReadPipe($worker) {
        $readPipe = $worker->getReadPipe();
        $this->assertEquals($readPipe, $worker->getPipes()[Worker::STDOUT]);
        
        return $worker;
    }
    
    function testDestruct() {
        $worker = new Worker(PHP_BINARY);
        $worker->__destruct();
    }
    
}
