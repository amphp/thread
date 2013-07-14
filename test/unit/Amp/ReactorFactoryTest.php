<?php

use Amp\ReactorFactory;

class ReactorFactoryTest extends PHPUnit_Framework_TestCase {
    
    function testSelectReturnsLibeventReactorIfExtensionLoaded() {
        if (!extension_loaded('libevent')) {
            $this->markTestSkipped(
                'libevent extension not available'
            );
        }
        
        $rf = new ReactorFactory;
        $reactor = $rf->select();
        $this->assertInstanceOf('Amp\LibeventReactor', $reactor);
    }
    
    function testMagicInvokeDelegatesToSelectMethod() {
        $rf = $this->getMock('Amp\ReactorFactory', ['select']);
        $rf->expects($this->once())
           ->method('select')
           ->will($this->returnValue(42));
        
        $this->assertEquals(42, $rf->__invoke());
    }
}

