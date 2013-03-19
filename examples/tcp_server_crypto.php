<?php

use Amp\ReactorFactory,
    Amp\Server\TcpServerCrypto;

date_default_timezone_set('GMT');

require dirname(__DIR__) . '/autoload.php';

/**
 * Select the best available reactor for your system
 */
$reactor = (new ReactorFactory)->select();

/**
 * Generate a new crypto server instance and assign our TLS settings
 */
$encryptedServer = (new TcpServerCrypto($reactor, '127.0.0.1', 1443))->setAllOptions([
    'pemCertFile'        => __DIR__ . '/support_files/generated_cert.pem',
    'pemCertPassphrase'  => '42 is not a legitimate passphrase',
    
    /* --------- EVERYTHING ELSE IS OPTIONAL ------------- */
    
    'allowSelfSigned'    => NULL,   // default: TRUE
    'verifyPeer'         => NULL,   // default: FALSE
    'ciphers'            => NULL,   // default: "RC4-SHA:HIGH:!MD5:!aNULL:!EDH"
    'disableCompression' => NULL,   // default: TRUE
    'certAuthorityFile'  => NULL,   // -
    'certAuthorityDir'   => NULL    // -
]);


/**
 * Bind the server to the address::port we specified in the constructor and tell it how to respond
 * when a new client connects.
 */
$encryptedServer->listen(function($clientSock, $peerName, $serverName) {
    // read and write from the connected client socket
});

/**
 * Fire up the event reactor
 */
$reactor->run();

