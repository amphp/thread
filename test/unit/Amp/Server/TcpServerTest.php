<?php

use Amp\Server\TcpServer,
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
        $address = 'bad address';
        $port = 1337;
        
        $server = new TcpServer($reactor, $address, $port);
    }
    
    
    function provideBadPorts() {
        return [
            ['bad port'],
            [-1],
            [65536]
        ];
    }
    
    /**
     * @dataProvider provideBadPorts
     * @expectedException InvalidArgumentException
     */
    function testConstructorThrowsExceptionOnInvalidPort($port) {
        $reactor = $this->getMock('Amp\\Reactor');
        $address = '127.0.0.1';
        
        $server = new TcpServer($reactor, $address, $port);
    }
    
    function testConstructorAddsIpV6AddressBrackets() {
        $reactor = $this->getMock('Amp\\Reactor');
        $address = 'fe80::1';
        $port = 1337;
        
        $server = new TcpServer($reactor, $address, $port);
        
        $this->assertEquals("[$address]", $server->getAddress());
    }
    
    function testGetAddress() {
        $reactor = $this->getMock('Amp\\Reactor');
        $address = '127.0.0.1';
        $port = 1337;
        
        $server = new TcpServer($reactor, $address, $port);
        
        $this->assertEquals($address, $server->getAddress());
    }
    
    function testGetPort() {
        $reactor = $this->getMock('Amp\\Reactor');
        $address = '127.0.0.1';
        $port = 1337;
        
        $server = new TcpServer($reactor, $address, $port);
        
        $this->assertEquals($port, $server->getPort());
    }
    
    function testListenThrowsExceptionIfAlreadyListening() {
        $this->skipIfMissingExtLibevent();
        
        $reactor = (new ReactorFactory)->select();
        $address = '127.0.0.1';
        $port = 1337;
        
        $server = new TcpServer($reactor, $address, $port);
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
        $address = '127.0.0.1';
        $port = 1337;
        
        $server = new TcpServer($reactor, $address, $port);
        $server->listen(function(){});
        
        $server2 = new TcpServer($reactor, $address, $port);
        
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
        $address = '127.0.0.1';
        $port = 1337;
        
        $server = new TcpServer($reactor, $address, $port);
        $server->listen(function(){});
        $server->disable();
        $server->enable();
        $server->stop();
    }
    
    function testWildcardAddressResolvesToZeros() {
        $reactor = (new ReactorFactory)->select();
        $address = '*';
        $port = 1337;
        
        $server = new TcpServer($reactor, $address, $port);
        
        $this->assertEquals('0.0.0.0', $server->getAddress()); 
    }
    
}

