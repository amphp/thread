<?php

function my_hello_function($name) {
    return "Hello, $name!";
}

function my_error_function() {
    trigger_error('Test Userland Error');
    return 42;
}

function my_exception_function() {
    throw new Exception('test exception');
}

function my_fatal_function() {
    $notAnObject = TRUE;
    $notAnObject->someMethod(); // <-- will trigger a fatal E_ERROR
}
