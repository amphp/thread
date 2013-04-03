<?php

use Amp\Async\WorkerSessionFactory;

class WorkerSessionFactoryTest extends PHPUnit_Framework_TestCase {
    
    function testSetGranularity() {
        $wsf = new WorkerSessionFactory;
        $wsf->setGranularity(1);
    }
    
    function testInvoke() {
        $wsf = new WorkerSessionFactory;
        $this->assertInstanceOf('Amp\Async\WorkerSession', $wsf(PHP_BINARY));
    }
    
}
