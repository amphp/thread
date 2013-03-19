<?php

use Amp\ReactorFactory;

class ReactorFactoryTest extends PHPUnit_Framework_TestCase {
    
    function testSelectReturnsLibEventReactorIfExtensionLoaded() {
        if (!extension_loaded('libevent')) {
            $this->markTestSkipped(
                'libevent extension not available'
            );
        }
        
        $rf = new ReactorFactory;
        $reactor = $rf->select();
        $this->assertInstanceOf('Amp\\LibEventReactor', $reactor);
    }
    
}

