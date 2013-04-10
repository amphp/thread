<?php

namespace Amp\Server;

use Amp\Reactor;

class TcpServerCrypto extends TcpServer {
    
    protected $clientsPendingHandshake = [];
    protected $cryptoType = STREAM_CRYPTO_METHOD_TLS_SERVER;
    protected $handshakeTimeout = 1;
    protected $context = [
        'local_cert'          => NULL,
        'passphrase'          => NULL,
        'allow_self_signed'   => TRUE,
        'verify_peer'         => FALSE,
        'ciphers'             => 'RC4-SHA:HIGH:!MD5:!aNULL:!EDH',
        'disable_compression' => TRUE,
        'cafile'              => NULL,
        'capath'              => NULL
    ];
    
    protected static $contextMap = [
        'pemCertFile'        => 'local_cert',
        'pemCertPassphrase'  => 'passphrase',
        'allowSelfSigned'    => 'allow_self_signed',
        'verifyPeer'         => 'verify_peer',
        'ciphers'            => 'ciphers',
        'disableCompression' => 'disable_compression',
        'certAuthorityFile'  => 'cafile',
        'certAuthorityDir'   => 'capath'
    ];
    
    function setOption($option, $value) {
        if (!isset($value)) {
            return;
        }
        
        if (isset(self::$contextMap[$option])) {
            $contextKey = self::$contextMap[$option];
            $this->context[$contextKey] = $value;
        } elseif ($option == 'cryptoType') {
            $this->cryptoType = $value;
        } elseif ($option == 'handshakeTimeout') {
            $this->handshakeTimeout = (int) $value;
        } else {
            $this->context[$option] = $value;
        }
        
        return $this;
    }
    
    function setAllOptions(array $options) {
        foreach ($options as $key => $value) {
            $this->setOption($key, $value);
        }
        
        return $this;
    }
    
    function listen(callable $onClient) {
        if ($this->isBound) {
            throw new \RuntimeException(
                "Server is already bound to {$this->address}:{$this->port}"
            );
        }
        
        if (empty($this->context['local_cert'])) {
            throw new \UnexpectedValueException(
                'The `pemCertFile` option must be specified before binding a crypto-enabled server'
            );
        } elseif (empty($this->context['passphrase'])) {
            throw new \UnexpectedValueException(
                'The `pemCertPassphrase` option must be specified before binding a crypto-enabled server'
            );
        }
        
        $bindOn = 'tcp://' . $this->address . ':' . $this->port;
        $flags = STREAM_SERVER_BIND | STREAM_SERVER_LISTEN;
        $tlsContext = $this->generateTlsContext();
        
        if ($socket = stream_socket_server($bindOn, $errNo, $errStr, $flags, $tlsContext)) {
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
    }
    
    protected function generateTlsContext() {
        $contextOpts = [];
        foreach ($this->context as $key => $value) {
            if ($value !== NULL) {
                $contextOpts[$key] = $value;
            }
        }
        
        return stream_context_create(['ssl' => $contextOpts]);
    }
    
    /**
     * Overrides parent::accept() to enable TLS encryption before accepting the new client.
     */
    protected function accept($socket, callable $onClient) {
        while ($clientSock = @stream_socket_accept($socket, $timeout = 0)) {
            $onReadable = $this->reactor->onReadable($clientSock, function ($clientSock, $trigger) {
                $this->doHandshake($clientSock, $trigger);
            }, $this->handshakeTimeout);
            
            $clientId = (int) $clientSock;
            $this->clientsPendingHandshake[$clientId] = [$onReadable, $onClient];
            $this->doHandshake($clientSock, NULL);
        }
    }
    
    /**
     * Note that the strict `FALSE ===` check against the crypto result is required because a falsy
     * zero integer value is returned when the handshake is still pending.
     */
    protected function doHandshake($clientSock, $trigger) {
        if ($trigger == Reactor::TIMEOUT) {
            $this->failConnectionAttempt($clientSock);
        } elseif ($cryptoResult = @stream_socket_enable_crypto($clientSock, TRUE, $this->cryptoType)) {
            $clientId = (int) $clientSock;
            $pendingInfo = $this->clientsPendingHandshake[$clientId];
            list($onReadable, $onClient) = $pendingInfo;
            
            $onReadable->cancel();
            unset($this->clientsPendingHandshake[$clientId]);
            
            $onClient($clientSock);
        } elseif (FALSE === $cryptoResult) {
            $this->failConnectionAttempt($clientSock);
        }
    }
    
    protected function failConnectionAttempt($clientSock) {
        $clientId = (int) $clientSock;
        $onReadable = $this->clientsPendingHandshake[$clientId][0];
        $onReadable->cancel();
        
        @fclose($clientSock);
        
        unset($this->clientsPendingHandshake[$clientId]);
    }
    
}

