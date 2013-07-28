<?php

require dirname(__DIR__) . '/autoload.php'; // <-- Register an AMP autoloader

use Amp\Reactor,
    Amp\ReactorFactory,
    Amp\Dispatch\PhpDispatcher;

class MyParallelProgram {
    
    private $reactor;
    private $dispatcher;
    
    function __construct(Reactor $reactor, PhpDispatcher $dispatcher) {
        $this->reactor = $reactor;
        $this->dispatcher = $dispatcher;
    }
    
    function run() {
        $this->dispatchAsynchronousSleepCall();
        $this->scheduleOneSecondTickOutput();
        $this->reactor->run(); // <-- Start the event reactor
    }
    
    private function dispatchAsynchronousSleepCall() {
        $this->reactor->once(function() {
            $afterSleepCallReturns = function() { $this->reactor->stop(); };
            $this->dispatcher->call($afterSleepCallReturns, 'sleep', 3);
        });
    }
    
    private function scheduleOneSecondTickOutput() {
        $tickFunction = function() { echo "tick ", time(), "\n"; };
        $this->reactor->schedule($tickFunction, $intervalInSeconds = 1);
    }
}

$reactor    = (new ReactorFactory)->select();
$dispatcher = new PhpDispatcher($reactor, $userFuncsFile = '', $processes = 2);
$program    = new MyParallelProgram($reactor, $dispatcher);

$program->run(); // <-- Won't return control until $reactor->stop() is called
