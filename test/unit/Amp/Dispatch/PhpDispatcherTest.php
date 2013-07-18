<?php

use Amp\Dispatch\PhpDispatcher;

class PhpDispatcherTest extends PHPUnit_Framework_TestCase {
    
    /**
     * @expectedException \InvalidArgumentException
     */
    function testConstructorThrowsOnBadWorkerCommand() {
        $reactor = $this->getMock('Amp\Reactor');
        $nonexistentFilePath = 'adsfaj;dflkjfasg;adhfkjdhsfashfahfljdshafkhsdalfs';
        $bad = new PhpDispatcher($reactor, $nonexistentFilePath);
    }
    
}
