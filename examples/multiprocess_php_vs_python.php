<?php

/**
 * This script counts how many times AMP can calculate the length of a string and return the result
 * asynchronously in each language over a period of time using the multiprocessing dispatcher.
 */

use Amp\UnserializedIoDispatcher, Amp\IoDispatcher, Alert\ReactorFactory;

require __DIR__ . '/../vendor/autoload.php';

define('RUN_TIME', 3);

// ------------------------------------- PYTHON --------------------------------------------------->

$reactor = (new ReactorFactory)->select();
$workerCmd = '/usr/bin/python ' . __DIR__ . '/support/python_len_benchmark.py';
$pythonDispatcher = new UnserializedIoDispatcher($reactor, $workerCmd, $workerProcessesToSpawn = 1);

$pythonCount = 0;
$pythonOnResult = function() use (&$pythonCount) {
    $pythonCount++;
};

$reactor->once(function() use ($reactor) {
    $reactor->stop();
}, $delayInSeconds = RUN_TIME);

$reactor->repeat(function() use ($pythonDispatcher, $pythonOnResult) {
    $pythonDispatcher->call($pythonOnResult, 'len', 'zanzibar');
}, $interval = 0);

echo "Running Python len() benchmark for ", RUN_TIME, " seconds ...\n";

$reactor->run();
unset($pythonDispatcher);
echo "Python benchmark complete.\n";

// ------------------------------------ PHP ------------------------------------------------------->

$reactor = (new ReactorFactory)->select();
$phpDispatcher = new IoDispatcher($reactor, $workerCmd = NULL, $workerProcessesToSpawn = 1);

$phpCount = 0;
$phpOnResult = function() use (&$phpCount) {
    $phpCount++;
};

$reactor->once(function() use ($reactor) {
    $reactor->stop();
}, $delayInSeconds = RUN_TIME);

$reactor->repeat(function() use ($phpDispatcher, $phpOnResult) {
    $phpDispatcher->call($phpOnResult, 'strlen', 'zanzibar');
}, $interval = 0);

echo "Running PHP strlen() benchmark for ", RUN_TIME, " seconds ...\n";

$reactor->run();

echo "PHP benchmark complete.\n";

// ---------------------------------- RESULTS ----------------------------------------------------->

$pythonRate = number_format(floor($pythonCount/RUN_TIME));
$phpRate = number_format(floor($phpCount/RUN_TIME));
$results = <<<EOT

---------------------------------------------------
Asynchronous string length calculations (%s seconds)
---------------------------------------------------

Python   len('zanzibar'):      %s/sec
PHP      strlen('zanzibar'):   %s/sec


EOT;

echo sprintf($results, RUN_TIME, $pythonRate, $phpRate);

