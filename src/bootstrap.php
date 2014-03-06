<?php

require __DIR__ . '/../vendor/Alert/src/bootstrap.php';

spl_autoload_register(function($class) {
    if (strpos($class, 'Amp\\') === 0) {
        $name = substr($class, strlen('Amp'));
        require __DIR__ . "/../lib" . strtr($name, '\\', DIRECTORY_SEPARATOR) . '.php';
    }
});
