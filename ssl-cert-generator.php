<?php

/**
 * Generates a self-signed PEM certificate file
 */
function createSslCert($pemCertFile, $pemCertPassphrase, $pemDistinguishedName) {
    // Create private key
    $privatekey = openssl_pkey_new();

    // Create and sign CSR
    $cert = openssl_csr_new($pemDistinguishedName, $privatekey);
    $cert = openssl_csr_sign($cert, NULL, $privatekey, $daysValidFor = 365);

    // Generate and save .PEM file
    $pem = [];
    openssl_x509_export($cert, $pem[0]);
    openssl_pkey_export($privatekey, $pem[1], $pemCertPassphrase);
    file_put_contents($pemCertFile, implode($pem));
    chmod($pemCertFile, 0600);
}

// ---------------------- CUSTOMIZE THE INFO BELOW BEFORE EXECUTING THIS FILE ----------------------

$pemCertFile = __DIR__ . "/examples/generated_cert.pem";
$pemCertPassphrase = "42 is not a legitimate passphrase";
$pemDistinguishedName = [
    "countryName"            => "US",               // country name
    "stateOrProvinceName"    => "XX",               // state or province name
    "localityName"           => "Anytown",          // your city name
    "organizationName"       => "N/A",              // company name
    "organizationalUnitName" => "N/A",              // department name
    "commonName"             => "localhost",        // full hostname
    "emailAddress"           => "me@example.com"    // email address
];

if (!file_exists($pemCertFile)) {
    createSslCert($pemCertFile, $pemCertPassphrase, $pemDistinguishedName);
}

