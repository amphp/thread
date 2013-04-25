<?php

use Amp\ReactorFactory,
    Amp\TcpServer;

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
        
        $reactor = (new ReactorFactory)->select();
        $server = (new IntegrationTcpServerTestStub($reactor))->defineBinding('127.0.0.1:1337');
        
        $reactor->once(function() {
            throw new Exception('TCP server integration test timed out');
        }, $delay = 1);
        
        $reactor->once(function() use ($reactor, $server) {
            $connectTo = '127.0.0.1:1337';
            $client = stream_socket_client($connectTo);
            
            $data = '';
            $reactor->onReadable($client, function() use ($reactor, $server, $client, &$data) {
                $data .= fgets($client);
                $this->assertEquals(42, $data);
                $server->stop();
                $reactor->stop();
            });
        });
        
        $server->start();
    }
    
}

class IntegrationTcpServerTestStub extends TcpServer {
    protected function onClient($socket) {
        $data = 42;
        $dataLen = strlen($data);
        while ($dataLen) {
            $bytesWritten = fwrite($socket, $data);
            $dataLen -= $bytesWritten;
        }
        fclose($socket);
    }
}
