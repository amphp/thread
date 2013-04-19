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
    function testConstructorThrowsExceptionOnInvalidIp() {
        $reactor = $this->getMock('Amp\\Reactor');
        $address = 'badaddress:1337';
        
        $server = new TcpServer($reactor, $address);
    }
    
    function testListenThrowsExceptionIfAlreadyListening() {
        $this->skipIfMissingExtLibevent();
        
        $reactor = (new ReactorFactory)->select();
        $address = '127.0.0.1:1337';
        
        $server = new TcpServer($reactor, $address);
        $server->listen(function(){});
        
        try {
            $server->listen(function(){});
            $this->fail('Expected exception not thrown');
        } catch (RuntimeException $e) {
            $server->stop();
        }
    }
    
    function testListenThrowsExceptionOnBindFailure() {
        $this->skipIfMissingExtLibevent();
        
        $reactor = (new ReactorFactory)->select();
        $address = '127.0.0.1:1337';
        
        $server = new TcpServer($reactor, $address);
        $server->listen(function(){});
        
        $server2 = new TcpServer($reactor, $address);
        
        try {
            $server2->listen(function(){});
            $this->fail('Expected exception not thrown');
        } catch (RuntimeException $e) {
            $server->stop();
        }
    }
    
    function testEnableDisable() {
        $this->skipIfMissingExtLibevent();
        
        $reactor = (new ReactorFactory)->select();
        $address = '127.0.0.1:1337';
        
        $server = new TcpServer($reactor, $address);
        $server->listen(function(){});
        $server->disable();
        $server->enable();
        $server->stop();
    }
    
}

