<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

use Alert\Reactor,
    Alert\NativeReactor,
    Amp\Dispatcher,
    Amp\ThreadedDispatcher,
    Amp\CallResult;

require __DIR__ . '/vendor/autoload.php';

class Benchmarker {

    private $reactor;
    private $dispatcher;
    private $results;
    private $errors;
    private $startMicrotime;
    private $elapsedTime;

    function __construct(Reactor $reactor, Dispatcher $dispatcher) {
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
        
        array_push($dispatchArgs, function(CallResult $result) use ($iterations, $displayOn) {
            //var_dump($result->getResult());
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
$dispatcher = new ThreadedDispatcher($reactor);
$dispatcher->start(1);
$benchmarker = new Benchmarker($reactor, $dispatcher);
$benchmarker->benchmark([
    'strlen',
    'zanzibar'
]);


/*
$taskId = 0;
$reactor = new NativeReactor;
$dispatcher = new ThreadedDispatcher($reactor);
$dispatcher->start(3);
$reactor->immediately(function() use ($dispatcher, $reactor, &$taskId) {
    $callId = $dispatcher->call('sleep', 30, function($result) {
        printf("Did result fail? %s\n", $result->failed() ? 'YES' : 'NO');
    });
    
    $taskId = $callId;
});

$reactor->once(function() use ($dispatcher, $reactor, &$taskId) {
    if ($dispatcher->cancel($taskId)) {
        echo "cancelled!\n";
        $reactor->stop();
    }
}, 0.1);
$reactor->run();
*/

