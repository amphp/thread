<?php

/**
 * This file exists as a convenience for people who don't want to use composer
 */

spl_autoload_register(function($class) {
    if (0 === strpos($class, 'Amp\\')) {
        $class = str_replace('\\', DIRECTORY_SEPARATOR, $class);
        $file = __DIR__ . "/src/$class.php";
        if (file_exists($file)) {
            require $file;
        }
    } elseif (0 === strpos($class, 'Alert\\')) {
        $class = str_replace('\\', DIRECTORY_SEPARATOR, $class);
        $file = __DIR__ . "/vendor/Alert/src/$class.php";
        if (file_exists($file)) {
            require $file;
        }
    }
});
