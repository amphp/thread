<?php

use Amp\Dispatch\WorkerSessionFactory;

class WorkerSessionFactoryTest extends PHPUnit_Framework_TestCase {
    
    function testSetGranularity() {
        $wsf = new WorkerSessionFactory;
        $wsf->setGranularity(1);
    }
    
    function testInvoke() {
        $wsf = new WorkerSessionFactory;
        $this->assertInstanceOf('Amp\Dispatch\WorkerSession', $wsf(PHP_BINARY));
    }
    
}
