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
    
    function testSelectReturnsNativeReactorIfLibeventNotLoaded() {
        $rf = $this->getMock('Amp\ReactorFactory', ['hasLibevent']);
        $rf->expects($this->once())
           ->method('hasLibevent')
           ->will($this->returnValue(FALSE));
        
        $reactor = $rf->select();
        $this->assertInstanceOf('Amp\NativeReactor', $reactor);
    }
}

