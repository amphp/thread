<?php

/**
 * This script counts how many times Amp can calculate the length of a string and return the result
 * asynchronously in each language over a period of time.
 */

use Amp\Async\Dispatcher,
    Amp\Async\PhpDispatcher,
    Amp\ReactorFactory;

require dirname(__DIR__) . '/autoload.php';

define('RUN_TIME', 5);

// ------------------------------------- PYTHON --------------------------------------------------->

$reactor = (new ReactorFactory)->select();
$workerCmd = '/usr/bin/python ' . __DIR__ . '/support_files/python_len_benchmark.py';
$pythonDispatcher = new Dispatcher($reactor, $workerCmd, $poolSize = 1);
$pythonDispatcher->start();

$pythonCount = 0;
$pythonOnResult = function() use (&$pythonCount) {
    $pythonCount++;
};

$reactor->once(function() use ($reactor) {
    $reactor->stop();
}, $delayInSeconds = RUN_TIME);

$reactor->repeat(function() use ($pythonDispatcher, $pythonOnResult) {
    $pythonDispatcher->call($pythonOnResult, 'len', 'zanzibar');
});

echo "Running Python len() benchmark for ", RUN_TIME, " seconds ...\n";

$reactor->run();
unset($pythonDispatcher);
echo "Python benchmark complete.\n";

// ------------------------------------ PHP ------------------------------------------------------->

$reactor = (new ReactorFactory)->select();
$phpDispatcher = new PhpDispatcher($reactor, $workerCmd = NULL, $poolSize = 1);
$phpDispatcher->start();

$phpCount = 0;
$phpOnResult = function() use (&$phpCount) {
    $phpCount++;
};

$reactor->once(function() use ($reactor) {
    $reactor->stop();
}, $delayInSeconds = RUN_TIME);

$reactor->repeat(function() use ($phpDispatcher, $phpOnResult) {
    $phpDispatcher->call($phpOnResult, 'strlen', 'zanzibar');
});

echo "Running PHP strlen() benchmark for ", RUN_TIME, " seconds ...\n";

$reactor->run();

echo "PHP benchmark complete.\n";

// ---------------------------------- RESULTS ----------------------------------------------------->

$results = <<<EOT

-------------------------------------------------
Asynchronous string length executions (%s seconds)
-------------------------------------------------

Python   len('zanzibar'):      $pythonCount
PHP      strlen('zanzibar'):   $phpCount


EOT;

echo sprintf($results, RUN_TIME);

