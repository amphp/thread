## AMP: Asynchronous Multiprocessing for PHP

AMP parallelizes asynchronous RPC-style function calls to a pool of worker processes inside 
non-blocking event loops. The OS-agnostic functionality is available in both Windows *and* POSIX
environments and requires no PHP extensions.

Current evented PHP libraries require non-blocking semantics for computational concurrency. This is
a serious mistake. The solution to the problem of event-driven concurrency is *NOT* to write and
require non-blocking versions of every imaginable PHP function. Instead, we should allow evented
code to utilize the vast synchronous toolkit already available to PHP developers.

> **HEY!** Checkout out the [**EXAMPLES**](https://github.com/rdlowrey/Amp/tree/master/examples)
> to see some of the cool things AMP can do.


### FEATURES

 - Offers a simple API for parallelizing function calls to a pool of worker processes;
 - Allows RPC-style calls to worker processes in languages that *aren't* PHP via a custom
   inter-process messaging protocol;
 - Provides full-featured native *AND* libevent event loops for out-of-the-box compatibility with
   any PHP install on any operating system;


### IN DEVELOPMENT

 - A lightweight threaded `Dispatcher` implementation to parallelize tasks to worker threads using
   PHP's [*pthreads*][pthreads] extension.
 - Socket server implementation for distributing work across different machines or requests in web
   SAPI environments.
 - An event reactor utilizing `Libev` via PHP's [*ev*][ev] extension.


### PROJECT GOALS

* Provide accessible parallelization and concurrency without non-blocking semantics;
* Utilize a performant event loop abstractiong for non-blocking socket IO and timer events;
* Establish a unified API for multi-processing, multi-threading and remote job dispatch;
* Build all components using [SOLID][solid], readable and thoroughly tested code.


### INSTALLATION

###### Git:

```bash
$ git clone https://github.com/rdlowrey/Amp.git
```
###### Manual Download:

Manually download from the [tagged release][tags] section.

###### Composer:

```bash
$ php composer.phar require rdlowrey/amp:0.1.*
```


### REQUIREMENTS

* PHP 5.4+
* (optional) [*libevent*][libevent] Required to use the `LibeventReactor` for ~3x faster event-loop execution.


### BASIC USAGE

```php
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

$program->run(); // <-- Won't return until we call $reactor->stop()
```

[pthreads]: http://pecl.php.net/package/pthreads "pthreads"
[ev]: http://pecl.php.net/package/ev "ev"
[solid]: http://en.wikipedia.org/wiki/SOLID_(object-oriented_design) "S.O.L.I.D."
[tags]: https://github.com/rdlowrey/Amp/releases "Tagged Releases"
[libevent]: http://pecl.php.net/package/libevent "libevent"
