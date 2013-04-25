<?php

namespace Amp;

abstract class TcpServer {
    
    const IPV4_WILDCARD = '*';
    
    protected $reactor;
    protected $address = [];
    protected $servers = [];
    protected $addressTlsContextMap = [];
    protected $pendingTlsClients = [];
    protected $tlsDefaults = [
        'local_cert'          => NULL,
        'passphrase'          => NULL,
        'allow_self_signed'   => TRUE,
        'verify_peer'         => FALSE,
        'ciphers'             => 'RC4-SHA:HIGH:!MD5:!aNULL:!EDH',
        'disable_compression' => TRUE,
        'cafile'              => NULL,
        'capath'              => NULL
    ];
    protected $tlsHandshakeTimeout = 3;
    protected $cryptoType = STREAM_CRYPTO_METHOD_TLS_SERVER;
    protected $acceptSubscriptions = [];
    protected $isStarted = FALSE;
    protected $isPaused = FALSE;
    
    function __construct(Reactor $reactor) {
        $this->reactor = $reactor;
    }
    
    abstract protected function onClient($clientSock);
    
    function defineBinding($name, array $tls = []) {
        list($addr, $port) = $this->parseName($name);
        
        $addr = $this->validateAddr($addr);
        $port = $this->validatePort($port);
        
        $address = 'tcp://' . $addr . ':' . $port;
        
        $this->address[] = $address;
        $this->addressTlsContextMap[$address] = $tls ? $this->generateTlsContext($tls) : NULL;
        
        return $this;
    }
    
    protected function parseName($name) {
        if ($portStartPos = strrpos($name, ':')) {
            $addr = substr($name, 0, $portStartPos);
            $port = substr($name, $portStartPos + 1);
        } else {
            throw new \InvalidArgumentException(
                'Invalid server name; names must match the IP:PORT format'
            );
        }
        
        return [$addr, $port];
    }
    
    protected function validateAddr($addr) {
        $addr = trim($addr, '[]');
        
        if ($addr == self::IPV4_WILDCARD) {
            $validatedAddr = '0.0.0.0';
        } elseif (filter_var($addr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $validatedAddr = $addr;
        } elseif (filter_var($addr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $validatedAddr = '[' . $addr . ']';
        } else {
            throw new \InvalidArgumentException(
                'Invalid server address: ' . $addr
            );
        }
        
        return $validatedAddr;
    }
    
    protected function validatePort($port) {
        $validatedPort = filter_var($port, FILTER_VALIDATE_INT, ['options' => [
            'max_range' => 65535,
            'min_range' => 0
        ]]);
        
        if (FALSE === $validatedPort) {
            throw new \InvalidArgumentException(
                'Invalid server port: ' . $port
            );
        }
        
        return $validatedPort;
    }
    
    protected function generateTlsContext($tls) {
        $tls = array_filter($tls, function($value) { return isset($value); });
        $tls = array_merge($this->tlsDefaults, $tls);
        $this->validateTlsArray($tls);
        
        return stream_context_create(['ssl' => $tls]);
    }
    
    protected function validateTlsArray($tls) {
        if (empty($tls['local_cert'])) {
            throw new \UnexpectedValueException(
                '`local_cert` TLS setting required to bind crypto-enabled server'
            );
        } elseif (empty($tls['passphrase'])) {
            throw new \UnexpectedValueException(
                '`passphrase` TLS setting required to bind crypto-enabled server'
            );
        }
    }
    
    function start() {
        if (!$this->isStarted) {
            foreach ($this->address as $address) {
                $this->bindServer($address);
            }
            $this->isStarted = TRUE;
            $this->reactor->run();
        }
    }
    
    protected function bindServer($address) {
        $flags = STREAM_SERVER_BIND | STREAM_SERVER_LISTEN;
        
        if ($this->addressTlsContextMap[$address]) {
            $context = $this->tlsContexts[$address];
            $acceptFunc = function($server) { $this->acceptTls($server); };
        } else {
            $context = stream_context_create();
            $acceptFunc = function($server) { $this->accept($server); };
        }
        
        if ($server = @stream_socket_server($address, $errNo, $errStr, $flags, $context)) {
            stream_set_blocking($server, FALSE);
            $serverId = (int) $server;
            $this->servers[$serverId] = $server;
            $this->acceptSubscriptions[$serverId] = $this->reactor->onReadable($server, $acceptFunc);
        } else {
            throw new \RuntimeException(
                "Failed binding server to $address: [Error# $errNo] $errStr"
            );
        }
    }
    
    protected function accept($server) {
        while ($clientSock = @stream_socket_accept($server, $timeout = 0)) {
            $this->onClient($clientSock);
        }
    }
    
    protected function acceptTls($server) {
        $serverId = (int) $server;
        
        while ($clientSock = @stream_socket_accept($server, $timeout = 0)) {
            $clientId = (int) $clientSock;
            $this->pendingTlsClients[$clientId] = NULL;
            
            if (!$this->doTlsHandshake($clientSock, $trigger = NULL)) {
                $handshakeSub = $this->reactor->onReadable($clientSock, function ($clientSock, $trigger) {
                    $this->doTlsHandshake($clientSock, $trigger);
                }, $this->tlsHandshakeTimeout);
                
                $this->pendingTlsClients[$clientId] = $handshakeSub;
            }
        }
    }
    
    protected function doTlsHandshake($clientSock, $trigger) {
        if ($trigger === Reactor::TIMEOUT) {
            $this->failTlsConnection($clientSock);
            $result = FALSE;
        } elseif ($cryptoResult = stream_socket_enable_crypto($clientSock, TRUE, $this->cryptoType)) {
            $this->clearPendingClient($clientSock);
            $this->onClient($clientSock);
            $result = TRUE;
        } elseif (FALSE === $cryptoResult) {
            $this->failTlsConnection($clientSock);
            $result = FALSE;
        } else {
            $result = FALSE;
        }
        
        return $result;
    }
    
    protected function failTlsConnection($clientSock) {
        $this->clearPendingClient($clientSock);
        if (is_resource($clientSock)) {
            @fclose($clientSock);
        }
    }
    
    protected function clearPendingClient($clientSock) {
        $clientId = (int) $clientSock;
        if ($handshakeSub = $this->pendingTlsClients[$clientId]) {
            $handshakeSub->cancel();
        }
        unset($this->pendingTlsClients[$clientId]);
    }
    
    /**
     * Temporarily stop accepting new connections but do not unbind the socket servers
     */
    function pause() {
        if ($this->isStarted && !$this->isPaused) {
            foreach ($this->acceptSubscriptions as $subscription) {
                $subscription->disable();
            }
            
            $this->isPaused = TRUE;
        }
    }
    
    /**
     * Resume accepting new connections on the bound socket servers
     */
    function resume() {
        if ($this->isStarted && $this->isPaused) {
            foreach ($this->acceptSubscriptions as $subscription) {
                $subscription->enable();
            }
            
            $this->isPaused = FALSE;
        }
    }
    
    /**
     * Stop accepting client connections and unbind the socket servers
     */
    function stop() {
        if ($this->isStarted) {
            $this->clearAcceptSubscriptions();
            $this->stopServers();
            $this->isStarted = FALSE;
            $this->isPaused = FALSE;
        }
    }
    
    protected function clearAcceptSubscriptions() {
        foreach ($this->acceptSubscriptions as $subscription) {
            $subscription->cancel();
        }
        
        $this->acceptSubscriptions = [];
    }
    
    protected function stopServers() {
        foreach ($this->servers as $socket) {
            if (is_resource($socket)) {
                @fclose($socket);
            }
        }
        
        $this->servers = [];
    }
    
}

