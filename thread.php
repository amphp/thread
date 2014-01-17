<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

use Alert\Reactor,
    Alert\NativeReactor,
    Amp\ThreadDispatcher,
    Amp\PthreadsDispatcher,
    Amp\TaskResult;

require __DIR__ . '/autoload.php';

class Benchmarker {

    private $reactor;
    private $dispatcher;
    private $results;
    private $errors;
    private $startMicrotime;
    private $elapsedTime;

    function __construct(Reactor $reactor, ThreadDispatcher $dispatcher) {
        $this->reactor = $reactor;
        $this->dispatcher = $dispatcher;
    }

    function benchmark(array $dispatchArgs, $options = []) {
        $this->results = 0;
        $this->errors = 0;
        $this->startMicrotime = microtime(TRUE);

        $defaultOptions = ['workers' => 1, 'iterations' => 100000, 'displayOn' => 1000];
        $options = array_merge($defaultOptions, $options);
        $this->dispatcher->start($options['workers']);

        $iterations = $options['iterations'];
        $displayOn = $options['displayOn'];

        array_push($dispatchArgs, function(TaskResult $result) use ($iterations, $displayOn) {
            $this->errors += $result->failed();
            if (++$this->results % $displayOn !== 0) {
                return;
            }

            $this->elapsedTime = microtime(TRUE) - $this->startMicrotime;
            $this->outputIterationStats();
            //$this->outputMemoryStats();
            if ($this->results >= $iterations) {
                $this->reactor->stop();
            }
        });

        $this->reactor->repeat(function() use ($dispatchArgs) {
            call_user_func_array([$this->dispatcher, 'call'], $dispatchArgs);
        }, 0);

        $this->reactor->run();
    }

    private function outputIterationStats() {
        printf("results: %8d | errors: %8d | rate/s: %8.3f | elapsed: %5.2f\n",
            $this->results,
            $this->errors,
            $this->results / $this->elapsedTime,
            $this->elapsedTime
        );
    }

    private function outputMemoryStats() {
        gc_collect_cycles();
        printf("used: %10d | allocated: %10d | peak: %10d | elapsed: %5.2f\n",
            memory_get_usage(),
            memory_get_usage(TRUE),
            memory_get_peak_usage(TRUE),
            $this->elapsedTime
        );
    }

}

$reactor = new NativeReactor;
$dispatcher = new PthreadsDispatcher($reactor);
$benchmarker = new Benchmarker($reactor, $dispatcher);
$benchmarker->benchmark([
    'strlen',
    'zanzibar'
], $options = [
    'workers' => 8
]);
