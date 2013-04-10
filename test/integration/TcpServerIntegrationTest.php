<?php

use Amp\ReactorFactory,
    Amp\Server\TcpServer;

class TcpServerIntegrationTest extends PHPUnit_Framework_TestCase {
    
    private function skipIfMissingExtLibevent() {
        if (!extension_loaded('libevent')) {
            $this->markTestSkipped(
                'libevent extension not available'
            );
        }
    }
    
    function testBasicServerClientConnectAndDataReceipt() {
        $this->skipIfMissingExtLibevent();
        
        $address = '127.0.0.1';
        $port = 1337;
        
        $reactor = (new ReactorFactory)->select();
        $server = new TcpServer($reactor, $address, $port);
        
        $reactor->once(function() {
            throw new Exception('TCP server integration test timed out');
        }, $delay = 2);
        
        $reactor->once(function() use ($reactor, $server) {
            $connectTo = $server->getAddress() . ':' . $server->getPort();
            $client = stream_socket_client($connectTo);
            
            $data = '';
            $reactor->onReadable($client, function() use ($reactor, $server, $client, &$data) {
                $data .= fgets($client);
                if (strlen($data) > 1) {
                    $this->assertEquals(42, $data);
                    $server->stop();
                    $reactor->stop();
                }
            });
        });
        
        $server->listen(function($client) {
            $data = 42;
            $dataLen = strlen($data);
            while ($dataLen) {
                $bytesWritten = fwrite($client, $data);
                $dataLen -= $bytesWritten;
            }
        });
        
        $reactor->run();
    }
    
}

