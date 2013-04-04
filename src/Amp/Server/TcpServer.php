<?php

namespace Amp\Server;

use Amp\Reactor;

class TcpServer {
    
    protected $reactor;
    protected $address;
    protected $port;
    protected $socket;
    protected $acceptSubscription;
    
    protected $isBound = FALSE;
    
    function __construct(Reactor $reactor, $address, $port) {
        $this->reactor = $reactor;
        $this->setAddress($address);
        $this->setPort($port);
    }
    
    protected function setAddress($address) {
        $address = trim($address, '[]');
        
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $this->address = $address;
        } elseif (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $this->address = '[' . $address . ']';
        } else {
            throw new \InvalidArgumentException(
                'Invalid server address'
            );
        }
    }
    
    protected function setPort($port) {
        $this->port = filter_var($port, FILTER_VALIDATE_INT, ['options' => [
            'max_range' => 65535,
            'min_range' => 0
        ]]);
        
        if (FALSE === $this->port) {
            throw new \InvalidArgumentException(
                'Invalid server port'
            );
        }
    }
    
    /**
     * Listen on the defined INTERFACE:PORT, invoking the supplied callable on new connections
     * 
     * Applications must start the event reactor separately.
     * 
     * @param callable $onClient The callable to invoke when new connections are established
     * @return void
     */
    function listen(callable $onClient) {
        if ($this->isBound) {
            throw new \RuntimeException(
                "Server is already bound to {$this->address}:{$this->port}"
            );
        }
        
        $bindOn = 'tcp://' . $this->address . ':' . $this->port;
        $flags = STREAM_SERVER_BIND | STREAM_SERVER_LISTEN;
        
        if ($socket = @stream_socket_server($bindOn, $errNo, $errStr, $flags)) {
            stream_set_blocking($socket, FALSE);
            $this->socket = $socket;
            $this->acceptSubscription = $this->reactor->onReadable($socket, function($socket) use ($onClient) {
                $this->accept($socket, $onClient);
            });
            $this->isBound = TRUE;
        } else {
            throw new \RuntimeException(
                "Failed binding server on $bindOn: [Error# $errNo] $errStr"
            );
        }
        
        return $this;
    }
    
    protected function accept($socket, callable $onClient) {
        $serverName = stream_socket_get_name($socket, FALSE);
        
        while ($clientSock = @stream_socket_accept($socket, 0, $peerName)) {
            $onClient($clientSock, $peerName, $serverName);
        }
    }
    
    /**
     * Temporarily stop accepting new connections but do not unbind the socket
     */
    function disable() {
        if ($this->isBound) {
            $this->acceptSubscription->disable();
        }
    }
    
    /**
     * Resume accepting new connections on the bound socket
     */
    function enable() {
        if ($this->isBound) {
            $this->acceptSubscription->enable();
        }
    }
    
    /**
     * Stop accepting client connections and unbind the server
     */
    function stop() {
        if ($this->isBound) {
            $this->acceptSubscription->cancel();
            $this->acceptSubscription = NULL;
            
            $this->closeSocket();
            
            $this->isBound = FALSE;
        }
    }
    
    protected function closeSocket() {
        if (is_resource($this->socket)) {
            @fclose($this->socket);
        }
    }
    
    /**
     * Retrieve the server's address
     */
    function getAddress() {
        return $this->address;
    }
    
    /**
     * Retrieve the port on which the server listens
     */
    function getPort() {
        return $this->port;
    }
    
}

