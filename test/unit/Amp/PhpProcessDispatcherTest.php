<?php

use Amp\IoDispatcher;

class IoDispatcherTest extends PHPUnit_Framework_TestCase {
    
    /**
     * @expectedException \InvalidArgumentException
     */
    function testConstructorThrowsOnBadWorkerCommand() {
        $reactor = $this->getMock('Alert\Reactor');
        $nonexistentFilePath = 'adsfaj;dflkjfasg;adhfkjdhsfashfahfljdshafkhsdalfs';
        $bad = new IoDispatcher($reactor, $nonexistentFilePath);
    }
    
}
