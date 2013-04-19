<?php

namespace Amp;

class Connection {
    
    private $socket;
    private $address;
    private $port;
    private $peerAddress;
    private $peerPort;
    private $isConnected = TRUE;
    private $isEncrypted;
    private $soLinger;
    
    private $writeBuffer = '';
    private $writeBufferLen = 0;
    
    private $listeners = [
        'DONE' => [],
        'DATA' => []
    ];
    
    function __construct($socket) {
        $this->socket = $socket;
        
        $serverName = stream_socket_get_name($socket, FALSE);
        list($this->address, $this->port) = $this->parseName($serverName);
        
        $clientName = stream_socket_get_name($socket, TRUE);
        list($this->peerAddress, $this->peerPort) = $this->parseName($clientName);
        
        $this->isEncrypted = isset(stream_context_get_options($socket)['ssl']);
    }
    
    function __destruct() {
        if ($this->isConnected) {
            $this->close();
        }
    }
    
    private function parseName($name) {
        $portStartPos = strrpos($name, ':');
        $addr = substr($name, 0, $portStartPos);
        $port = substr($name, $portStartPos + 1);
        
        return [$addr, $port];
    }
    
    function getSocket() {
        return $this->socket;
    }
    
    function subscribe(array $observers) {
        foreach ($observers as $event => $callable) {
            $this->listeners[$event][] = $callable;
        }
    }
    
    function getAddress() {
        return $this->address;
    }
    
    function getPort() {
        return $this->port;
    }
    
    function getPeerAddress() {
        return $this->peerAddress;
    }
    
    function getPeerPort() {
        return $this->peerPort;
    }
    
    function isConnected() {
        return $this->isConnected;
    }
    
    function isEncrypted() {
        return $this->isEncrypted;
    }
    
    function setSoLinger($seconds) {
        $this->soLinger = filter_var($seconds, FILTER_VALIDATE_INT, ['options' => [
            'min_range' => 0,
            'default' => NULL
        ]]);
    }
    
    function setNoDelay() {
        // @TODO Allow disabling Nagle algorithm
    }
    
    function setKeepAlive() {
        // @TODO Allow modification of TCP keep-alive
    }
    
    function close() {
        if (!$this->isConnected) {
            return;
        }
        
        $isResource = is_resource($this->socket);
        
        if ($isResource && $this->soLinger !== NULL) {
            $this->closeWithSoLinger();
        } elseif ($isResource) {
            stream_socket_shutdown($this->socket, STREAM_SHUT_RDWR);
            fclose($this->socket);
        }
        
        $this->isConnected = FALSE;
        
        $this->notify('DONE', $this);
    }
    
    private function closeWithSoLinger() {
        if ($this->isEncrypted) {
            // socket extension can't import stream if it has crypto enabled
            @stream_socket_enable_crypto($this->socket, FALSE);
        }
        
        $rawSock = socket_import_stream($this->socket);
        
        if ($this->soLinger) {
            // non-zero SO_LINGER values require blocking sockets
            socket_set_block($rawSock);
        }
        
        socket_set_option($rawSock, SOL_SOCKET, SO_LINGER, [
            'l_onoff' => 1,
            'l_linger' => $this->soLinger
        ]);
        
        socket_close($rawSock);
        
        $this->socket = NULL;
    }
    
    function current() {
        if (!$this->isConnected) {
            return;
        }
        
        $data = @fread($this->socket, 8192);
        
        if ($data || $data === '0') {
            $this->notify('DATA', $data);
        } elseif (!is_resource($this->socket) || feof($this->socket)) {
            $this->close();
        }
    }
    
    function valid() {
        return $this->isConnected;
    }
    
    function rewind() {}
    function key() {}
    function next() {}
    
    function send($data = NULL) {
        if ($data || $data === '0') {
            $this->writeBuffer .= $data;
            $this->writeBufferLen += strlen($data);
        }
        
        if (!$this->writeBufferLen) {
            return TRUE;
        }
        
        $bytesWritten = @fwrite($this->socket, $this->writeBuffer);
        
        if ($bytesWritten === $this->writeBufferLen) {
            $this->writeBuffer = NULL;
            $this->writeBufferLen = NULL;
            $result = TRUE;
        } elseif ($bytesWritten) {
            $this->writeBuffer = substr($this->writeBuffer, $bytesWritten);
            $this->writeBufferLen -= $bytesWritten;
            $result = FALSE;
        } elseif (is_resource($this->socket)) {
            $result = FALSE;
        } else {
            $result = NULL;
            $this->close();
        }
        
        return $result;
    }
    
    private function notify($event, $data = NULL) {
        if (!empty($this->listeners[$event])) {
            foreach ($this->listeners[$event] as $callback) {
                $callback($data);
            }
        }
    }
}



























