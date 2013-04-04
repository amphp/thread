<?php

function dispatch_call_error_function() {
    throw new Exception(
        'This should result in a CALL_ERROR returned to the dispatcher'
    );
}

