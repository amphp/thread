<?php

use Amp\TcpServer,
    Amp\ReactorFactory;

class TcpServerTest extends PHPUnit_Framework_TestCase {
    
    private function skipIfMissingExtLibevent() {
        if (!extension_loaded('libevent')) {
            $this->markTestSkipped(
                'libevent extension not available'
            );
        }
    }
    
    /**
     * @expectedException InvalidArgumentException
     */
    function testDefineBindingThrowsExceptionOnInvalidIpPortCombo() {
        $reactor = $this->getMock('Amp\\Reactor');
        $address = 'badaddress:1337';
        
        $server = $this->getMock('Amp\TcpServer', ['onClient'], [$reactor]);
        $server->defineBinding($address);
    }
    
}
