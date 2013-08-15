<?php

namespace Amp\Test;

use Amp\JobDispatcher;

class JobDispatcherTest extends \PHPUnit_Framework_TestCase {

    function testConstruction() {
        $reactor = $this->getMock('Alert\Reactor');
        $obj = new JobDispatcher($reactor);
        $this->assertInstanceof('Amp\JobDispatcher', $obj);
    }

}
