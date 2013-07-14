<?php

use Amp\Dispatch\Process\WorkerSessionFactory;

class WorkerSessionFactoryTest extends PHPUnit_Framework_TestCase {
    
    function testSetGranularity() {
        $wsf = new WorkerSessionFactory;
        $wsf->setGranularity(1);
    }
    
    function testInvoke() {
        $wsf = new WorkerSessionFactory;
        $this->assertInstanceOf('Amp\Dispatch\Process\WorkerSession', $wsf(PHP_BINARY));
    }
    
}
