<?php

use Amp\Server\TcpServer;

class TcpServerTest extends PHPUnit_Framework_TestCase {
    
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
    
}

