<?php

namespace Amp;

class TcpServer {
    
    const IPV4_WILDCARD_ADDRESS = '*';
    
    private $reactor;
    private $servers = [];
    private $addresses = [];
    private $tlsContexts = [];
    private $acceptCallbacks = [];
    private $acceptSubscriptions = [];
    private $tlsClientsPendingHandshake = [];
    private $cachedConnectionCount = 0;
    private $isBound = FALSE;
    
    private $tlsDefaults = [
        'local_cert'          => NULL,
        'passphrase'          => NULL,
        'allow_self_signed'   => TRUE,
        'verify_peer'         => FALSE,
        'ciphers'             => 'RC4-SHA:HIGH:!MD5:!aNULL:!EDH',
        'disable_compression' => TRUE,
        'cafile'              => NULL,
        'capath'              => NULL
    ];
    
    private $maxConnections = 1000;
    private $cryptoType = STREAM_CRYPTO_METHOD_TLS_SERVER;
    private $handshakeTimeout = 3;
    
    function __construct(Reactor $reactor, $nameOrArray) {
        $this->reactor = $reactor;
        
        if (is_string($nameOrArray)) {
            $this->setListenOptions($nameOrArray, $tls = NULL);
        } elseif ($nameOrArray && is_array($nameOrArray)) {
            foreach ($nameOrArray as $nameAndTlsArr) {
                $name = array_shift($nameAndTlsArr);
                $tls = array_shift($nameAndTlsArr);
                $this->setListenOptions($name, $tls);
            }
            $this->servers = array_unique($this->servers);
        } else {
            throw new \InvalidArgumentException(
                'Invalid server name(s) specified at '.__CLASS__.'::'.__METHOD__.' Argument 2'
            );
        }
    }
    
    private function setListenOptions($name, array $tls = NULL) {
        list($addr, $port) = $this->parseName($name);
        
        $addr = $this->validateAddr($addr);
        $port = $this->validatePort($port);
        
        $address = 'tcp://' . $addr . ':' . $port;
        
        $this->addresses[] = $address;
        $this->tlsContexts[$address] = $tls ? $this->generateTlsContext($tls) : NULL;
    }
    
    private function parseName($name) {
        if ($portStartPos = strrpos($name, ':')) {
            $addr = substr($name, 0, $portStartPos);
            $port = substr($name, $portStartPos + 1);
        } else {
            throw new \InvalidArgumentException(
                'Invalid server name; names must match the form IP:PORT'
            );
        }
        
        return [$addr, $port];
    }
    
    private function validateAddr($addr) {
        $addr = trim($addr, '[]');
        
        if ($addr == self::IPV4_WILDCARD_ADDRESS) {
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
    
    private function validatePort($port) {
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
    
    private function generateTlsContext($tls) {
        $tls = array_filter(function($value) { return isset($value); }, $tls);
        $tls = array_merge($this->tlsDefaults, $tls);
        $this->validateTlsArray($tls);
        
        return stream_context_create(['ssl' => $tls]);
    }
    
    private function validateTlsArray($tls) {
        if (empty($tls['local_cert'])) {
            throw new \UnexpectedValueException(
                'The `local_cert` option must be specified before binding a crypto-enabled server'
            );
        } elseif (empty($tls['passphrase'])) {
            throw new \UnexpectedValueException(
                'The `passphrase` option must be specified before binding a crypto-enabled server'
            );
        }
    }
    
    function listen(callable $onClient) {
        if (!$this->isBound) {
            foreach ($this->addresses as $bindTo) {
                $this->bindServer($bindTo, $onClient);
            }
            $this->isBound = TRUE;
        } else {
            throw new \RuntimeException(
                "Server socket(s) already bound and listening"
            );
        }
        
        return $this;
    }
    
    private function bindServer($bindTo, $onClient) {
        $flags = STREAM_SERVER_BIND | STREAM_SERVER_LISTEN;
        
        if (isset($this->tlsContexts[$bindTo])) {
            $context = $this->tlsContexts[$bindTo];
            $isTls = TRUE;
        } else {
            $context = stream_context_create();
            $isTls = FALSE;
        }
        
        if ($server = @stream_socket_server($bindTo, $errNo, $errStr, $flags, $context)) {
            stream_set_blocking($server, FALSE);
            $serverId = (int) $server;
            
            $acceptFunc = $isTls
                ? function() use ($server) { $this->acceptTls($server); }
                : function() use ($server) { $this->accept($server); };
            
            $this->servers[$serverId] = $server;
            $this->acceptCallbacks[$serverId] = $onClient;
            $this->acceptSubscriptions[$serverId] = $this->reactor->onReadable($server, $acceptFunc);
        } else {
            throw new \RuntimeException(
                "Failed binding server to $bindTo: [Error# $errNo] $errStr"
            );
        }
    }
    
    private function accept($server) {
        $serverId = (int) $server;
        $onClient = $this->acceptCallbacks[$serverId];
        
        while ($clientSock = @stream_socket_accept($server, $timeout = 0)) {
            $connection = $this->generateConnectionFromClientSocket($clientSock);
            
            $onClient($connection);
            
            if (++$this->cachedConnectionCount >= $this->maxConnections) {
                $this->disable();
                break;
            }
        }
    }
    
    private function generateConnectionFromClientSocket($clientSock) {
        $connection = new Connection($clientSock);
        $connection->subscribe([
            'DONE' => function() use ($connection) { $this->close($connection); }
        ]);
        
        return $connection;
    }
    
    private function acceptTls($server) {
        $serverId = (int) $server;
        $onClient = $this->acceptCallbacks[$serverId];
        
        while ($clientSock = @stream_socket_accept($server, $timeout = 0)) {
            $handshakeSub = $this->reactor->onReadable($clientSock, function ($clientSock, $trigger) {
                $this->doTlsHandshake($clientSock, $trigger);
            }, $this->handshakeTimeout);
            
            $clientId = (int) $clientSock;
            $this->tlsClientsPendingHandshake[$clientId] = [$handshakeSub, $onClient];
            
            $this->cachedConnectionCount++;
            
            $this->doTlsHandshake($clientSock, NULL);
            
            if ($this->cachedConnectionCount >= $this->maxConnections) {
                $this->disable();
                break;
            }
        }
    }
    
    private function doTlsHandshake($clientSock, $trigger) {
        if ($trigger === Reactor::TIMEOUT) {
            $this->failTlsConnection($clientSock);
        } elseif ($cryptoResult = stream_socket_enable_crypto($clientSock, TRUE, $this->cryptoType)) {
            $clientId = (int) $clientSock;
            list($handshakeSub, $onClient) = $this->tlsClientsPendingHandshake[$clientId];
            unset($this->tlsClientsPendingHandshake[$clientId]);
            $handshakeSub->cancel();
            $connection = $this->generateConnectionFromClientSocket($clientSock);
            $onClient($connection);
        } elseif (FALSE === $cryptoResult) {
            // Note that the strict `FALSE ===` check is required here because a falsy zero integer
            // value is returned when the handshake is still pending.
            $this->failTlsConnection($clientSock);
        }
    }
    
    private function failTlsConnection($clientSock) {
        $clientId = (int) $clientSock;
        $handshakeSub = $this->tlsClientsPendingHandshake[$clientId][0];
        $handshakeSub->cancel();
        
        unset($this->tlsClientsPendingHandshake[$clientId]);
        
        @fclose($clientSock);
        
        $this->cachedConnectionCount--;
    }
    
    private function close(Connection $connection) {
        if ($this->cachedConnectionCount-- === $this->maxConnections) {
            $this->enable();
        }
    }
    
    /**
     * Temporarily stop accepting new connections but do not unbind the socket servers
     */
    function disable() {
        if ($this->isBound) {
            foreach ($this->acceptSubscriptions as $subscription) {
                $subscription->disable();
            }
        }
    }
    
    /**
     * Resume accepting new connections on the bound socket servers
     */
    function enable() {
        if ($this->isBound) {
            foreach ($this->acceptSubscriptions as $subscription) {
                $subscription->enable();
            }
        }
    }
    
    /**
     * Stop accepting client connections and unbind the socket servers
     */
    function stop() {
        if ($this->isBound) {
            $this->clearAcceptSubscriptions();
            $this->clearAcceptCallbacks();
            $this->stopServers();
            $this->isBound = FALSE;
        }
    }
    
    private function clearAcceptSubscriptions() {
        foreach ($this->acceptSubscriptions as $subscription) {
            $subscription->cancel();
        }
        
        $this->acceptSubscriptions = [];
    }
    
    private function clearAcceptCallbacks() {
        $this->acceptCallbacks = [];
    }
    
    private function stopServers() {
        foreach ($this->servers as $socket) {
            if (is_resource($socket)) {
                @fclose($socket);
            }
        }
        
        $this->servers = [];
    }
    
    function setMaxConnections($connCount) {
        $this->maxConnections = filter_var($connCount, FILTER_VALIDATE_INT, ['options' => [
            'min_range' => 0,
            'default' => 1000
        ]]);
    }
    
}

