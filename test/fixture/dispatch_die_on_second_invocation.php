<?php

$count = 0;

function dispatch_die_on_second_invocation() {
    global $count;
    
    if (++$count > 1) {
        die;
    } else {
        return 'woot';
    }
}

