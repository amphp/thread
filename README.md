AMP: Async Multi-threading in PHP (5.4+)
----------------------------------------

Amp parallelizes synchronous PHP function calls to worker thread pools in non-blocking applications.
The library dispatches blocking calls to worker threads where they can execute in parallel and
returns results asynchronously upon completion. All functionality is exposed in an OS-agnostic
manner by way of the [ext/pthreads][pthreads] extension.

**Problem Domain**

PHP has a cast catalog of synchronous libraries and extensions. However, it's generally hard to
find non-blocking libs for use inside event loops. Beyond this limitation, there are common tasks
(like filesystem IO) which don't play nice with the non-blocking paradigm. Unfortunately, threading
is an altogether different approach to concurrency from that used in non-blocking applications.
Amp seemlessly exposes threaded concurrency inside non-blocking PHP applications.


### Project Goals

* Expose threaded multiprocessing inside event-driven non-blocking applications;
* Build all components using [SOLID][solid], readable and unit-tested code.


### Requirements

* [PHP 5.4+][php-net] You'll need PHP.
* [ext/pthreads][pthreads] The pthreads extension ([windows .DLLs here][win-pthreads-dlls])
* [rdlowrey/alert][alert] Alert IO/events (retrieved automatically with `$ git clone --recursive`)

[php-net]: http://php.net "php.net"
[pthreads]: http://pecl.php.net/package/pthreads "pthreads"
[solid]: http://en.wikipedia.org/wiki/SOLID_(object-oriented_design) "S.O.L.I.D."
[alert]: https://github.com/rdlowrey/Alert "Alert IO/events"
[win-pthreads-dlls]: http://windows.php.net/downloads/pecl/releases/pthreads/ "pthreads Windows DLLs"

### Installation

**Git**

```bash
$ git clone --recursive git@github.com:rdlowrey/Amp.git
```

**Composer**

```bash
$ php composer.phar require rdlowrey/amp:0.4.*
```




## The Guide

**Intro**

* [Event Loop Basics](#event-loop-basics)

**Basic Usage**

* [Basic Calls](#basic-calls)
* [Userland Functions](#userland-functions)
* [Static Methods](#static-methods)
* [Magic Calls](#magic-calls)
* [Error Handling](#error-handling)
* [Task Timeouts](#task-timeouts)
* [Pool Size](#pool-size)
* [Thread Execution Limits](#thread-execution-limits)
* [Pthreads Context Flags](#pthreads-context-flags)

**Advanced Usage**

* [Stackable Tasks](#stackable-tasks)
* [Magic Tasks](#magic-tasks)
* [Class Autoloading](#class-autoloading)




### Intro

#### Event Loop Basics

Executing code inside an event loop allows us to use non-blocking libraries to perform many IO
operations at the same time. Instead of waiting for each individual operation to complete the event
loop assumes program flow and informs us when our tasks finish or actionable events occur. This
paradigm allows programs to execute other instructions when they would otherwise waste cycle waiting
for slow IO operations to complete. The non-blocking approach is particularly useful for scalable
network applications and servers where the naive thread-per-connection approach is untenable.

Unfortunately, robust applications generally require synchronous functionality and/or filesystem
operations that can't behave in a non-blocking manner. Amp was created to provide non-blocking
applications access to the full range of synchronous PHP functionality without blocking the main
event loop.

> **NOTE:** It's critical that any non-blocking libs in your application use the *same* event loop
> scheduler. The Amp dispatcher uses the [`Alert`][alert] event reactor for scheduling.

Because Amp executes inside an event loop, you'll see all of the following examples create a new
event reactor instance to kick things off. Once the event reactor is started it assumes program
control and *will not* return control until your application calls `Reactor::stop()`.



### Basic Usage


#### Basic Calls

The simplest way to use Amp is to dispatch calls to global functions:

```php
<?php

use Alert\Future, Alert\ReactorFactory, Amp\Dispatcher;

// Get an event reactor
$reactor = (new ReactorFactory)->select();

// Start the event loop and tell it to execute this closure immediately
$reactor->run(function() use ($reactor) {

    // Create our Dispatcher using the event reactor
    $dispatcher = new Dispatcher($reactor);

    // Invoke strlen('zanzibar') in a worker thread and
    // notify our callback when the result comes back
    $future = $dispatcher->call('strlen', 'zanzibar!');
    $future->onComplete(function(Future $f) use ($reactor) {
        printf("Woot! strlen('zanzibar') === %d", $f->getValue());
        $reactor->stop();
    });

});
```

The above example outputs the following to our console:

```
Woot! strlen('zanzibar') === 8
```

Obviously, the `strlen` call here is a spurious use of threaded concurrency; remember that it only
ever makes sense to dispatch work to a thread if the processing overhead of sending the call and
receiving the result is outweighed by the time that would otherwise be spent waiting for the result.
A more useful example demonstrates retrieving the contents of a filesystem resource:

```php
<?php
use Alert\ReactorFactory, Alert\Future, Amp\Dispatcher;

$reactor = (new ReactorFactory)->select();
$reactor->run(function() use ($reactor) {
    $dispatcher = new Dispatcher($reactor);
    $future = $dispatcher->call('file_get_contents', '/path/to/file');
    $future->onComplete(function(Future $f) use ($reactor) {
        var_dump($f->getValue());
        $reactor->stop();
    });
});
```

The above code retrieves the contents of the file at `/path/to/file` and var_dumps the result in
our main thread upon completion.


#### Userland Functions

We aren't limited to native functions. The `Amp\Dispatcher` can dispatch calls to userland
functions just as easily. Consider:

```php
<?php
use Alert\ReactorFactory, Alert\Future, Amp\Dispatcher;

function multiply($x, $y) {
    return $x * $y;
}

$reactor = (new ReactorFactory)->select();
$reactor->run(function() use ($reactor) {
    $dispatcher = new Dispatcher($reactor);
    $future = $dispatcher->call('multiply', 6, 7);
    $future->onComplete(function(Future $f) use ($reactor) {
        var_dump($f->getValue());
        $reactor->stop();
    });
});
```

The above code results in the following output:

```
int(42)
```


#### Static Methods

The `Dispatcher::call` method can handle any callable string, so we aren't limited to function
names. We can also dispatch calls to static class methods:

```php
<?php
use Alert\ReactorFactory, Alert\Future, Amp\Dispatcher;

class MyMultiplier {
    public static function multiply($x, $y) {
        return $x * $y;
    }
}

$reactor = (new ReactorFactory)->select();
$reactor->run(function() use ($reactor) {
    $dispatcher = new Dispatcher($reactor);
    $future = $dispatcher->call('MyMultiplier::multiply', 6, 7);
    $future->onComplete(function(Future $f) use ($reactor) {
        var_dump($f->getValue());
        $reactor->stop();
    });
});
```

The above code results in the following output:

```
int(42)
```

> **IMPORTANT:** In this example we've hardcoded the `MyMultiplier` class definition in the code.
> There is *no* class autoloading employed. There is no way for `ext/pthreads` to inherit globally
> registered autoloaders from the main thread. If you require autoloading in your worker threads
> you *MUST* dispatch a stackable task to define autoloader function(s) in your workers as
> demonstrated in the [Class Autoloading](#class-autoloading) section of this guide.


#### Magic Calls

Dispatchers take advantage of the magic `__call()` method to simply calls to functions in the global
namespace. Consider:

```php
<?php
use Alert\ReactorFactory, Alert\Future, Amp\Dispatcher;

$reactor = (new ReactorFactory)->select();
$reactor->run(function() use ($reactor) {
    $dispatcher = new Dispatcher($reactor);
    $future = $dispatcher->fopen('/path/to/file', 'r');
    $future->onComplete(function(Future $f) use ($reactor) {
        $fileHandle = $f->getValue();
        $reactor->stop();
    });
});
```

The above code opens a read-only file handle to the specified file and returns the result in
our main thread upon completion.


#### Error Handling

You may have noticed that our examples to this point have not returned results directly. Instead,
they return an instance of `Alert\Future`. These monadic placeholder objects allow us to distinguish
between successful results and errors. The most important thing to remember is this:

> Calling `Future::getValue()` will throw in the main thread if execution encountered a
> fatal error inside the worker thread.

This behavior makes it impossible to ignore execution failures. Of course, we can easily determine
if a call failed using the `Future::succeeded()` convenience method.
Consider the following examples ...

**Uncaught Exception**

```php
<?php
use Alert\ReactorFactory, Alert\Future, Amp\Dispatcher;

function myThrowingFunction() {
    throw new \RuntimeException('oh noes!!!');
}

$reactor = (new ReactorFactory)->select();
$reactor->run(function() use ($reactor) {
    $dispatcher = new Dispatcher($reactor);
    $future = $dispatcher->myThrowingFunction();
    $future->onComplete(function(Future $f) use ($reactor) {
        var_dump($f->succeeded()); // bool(false)
        var_dump($f->getError() instanceof Exception); // bool(true)
        try {
            $var = $f->getValue();
        } catch (Exception $e) {
            printf("Task result failed:\n\n%s", $e);
        }
        $reactor->stop();
    });
});
```

**Fatal Error**

In the following example we purposefully do something that will generate a fatal error in our
worker thread. Pthreads (and Amp) recover from this condition automatically. There is no need to
restart the thread pool and our main thread seamlessly recovers:

```php
<?php
use Alert\ReactorFactory, Alert\Future, Amp\Dispatcher;

function myFatalFunction() {
    $var = $nonexistentObject->nonexistentMethod();
}

$reactor = (new ReactorFactory)->select();
$reactor->run(function() use ($reactor) {
    $dispatcher = new Dispatcher($reactor);
    $future = $dispatcher->myFatalFunction();
    $future->onComplete(function(Future $f) use ($reactor) {
        var_dump($f->succeeded()); // bool(false)
        echo $f->getError(); // view the error traceback
        $reactor->stop();
    });
});
```

#### Task Timeouts

> **NOTE:** Relying on timeouts is almost always a poor design decision. You're much better served
> to solve the underlying problem that causes slow execution in your dispatched calls/tasks. This
> timeout functionality should primarily be used in server environments where long-running tasks
> could become a DoS attack vector.

Amp automatically times out tasks exceeding the (configurable) maximum allowed run-time. We can
customize this setting as shown in the following example:

```php
<?php
use Alert\ReactorFactory, Alert\Future, Amp\Dispatcher;

$reactor = (new ReactorFactory)->select();
$reactor->run(function() use ($reactor) {
    $dispatcher = new Dispatcher($reactor);

    // Only use one worker so our thread pool acts like a FIFO job queue
    $dispatcher->setOption(Dispatcher::OPT_POOL_SIZE, 1);

    // Limit per-call execution time to 2 seconds
    $dispatcher->setOption(Dispatcher::OPT_TASK_TIMEOUT, 2);

    // This function will timeout after two seconds
    $future = $dispatcher->sleep(9999);
    $future->onComplete(function(Future $f) {
        var_dump($f->succeeded()); // bool(false)
        var_dump($f->getError() instanceof Amp\TimeoutException); // bool(true)
    });

    // Queue another function behind the sleep() call
    $future = $dispatcher->multiply(6, 7);
    $future->onComplete(function(Future $f) use ($reactor) {
        var_dump($f->getValue()); // int(42)
        $reactor->stop();
    });
});
```

#### Pool Size

You may have noticed that in some of our previous examples we've explicity set a pool size option.
The effect of this setting should be obvious: it controls how many worker threads we spawn to handle
task dispatches. An example:

```php
<?php
use Alert\ReactorFactory, Amp\Dispatcher;

$reactor = (new ReactorFactory)->select();
$dispatcher = new Dispatcher($reactor);
$dispatcher->setOption(Dispatcher::OPT_POOL_SIZE, 16);
```

By default the `Amp\Dispatcher` will only spawn a single worker thread. In order to spawn
more this option must be assigned prior to calling the dispatcher's `start()` method (or dispatching
a call as this automatically calls `start()` to populate the thread pool). Setting this option after
the Dispatcher has started will have no effect.


#### Thread Execution Limits

In theory we shouldn't have to worry about sloppy code or extensions playing fast and loose with
memory resources. However in the real world this may not always be an option. Amp makes provision
for these scenarios by exposing a configurable limit setting to control how many dispatches a
worker thread will accept before being respawned to clean up any outstanding garbage. If you wish
to modify this setting simply set the relevant option:

```php
<?php
use Alert\ReactorFactory, Amp\Dispatcher;

$reactor = (new ReactorFactory)->select();
$dispatcher = new Dispatcher($reactor);
$dispatcher->setOption(Dispatcher::OPT_EXEC_LIMIT, 1024); // 1024 is the default
```

Users who wish to remove the execution limit you may set the value to `-1` as shown here:

```php
<?php
use Alert\ReactorFactory, Amp\Dispatcher;

$reactor = (new ReactorFactory)->select();
$dispatcher = new Dispatcher($reactor);
$dispatcher->setOption(Dispatcher::OPT_EXEC_LIMIT, -1);
```


#### Pthreads Context Flags

Users can control the context inheritance mask used to start worker threads by setting thread start
flags as shown here:

```php
<?php
use Alert\ReactorFactory, Amp\Dispatcher;

$reactor = (new ReactorFactory)->select();
$dispatcher = new Dispatcher($reactor);
$dispatcher->setOption(Dispatcher::OPT_THREAD_FLAGS, PTHREADS_INHERIT_NONE);
```

The full list of available flags can be found in the relevant [pthreads documentation page][pthreads-flags].

[pthreads-flags]: php.net/manual/en/pthreads.constants.php "pthreads flags"


### Advanced Usage

#### Stackable Tasks

While Amp abstracts much of the underlying pthreads functionality there are times when low-level
access is useful. For these scenarios Amp allows the specification of "tasks" extending pthreads
[`Stackable`][pthreads-stackables]. Stackables allow users to specify arbitrary code in the main
thread and use it for execution in worker threads.

> **NOTE:** All `Stackable` classes MUST (per pthreads) specify the abstract `Stackable::run()` method

Instances of your custom `Stackable` may then be passed to the `Dispatcher::execute()` method
for processing.

```php
<?php
use Alert\ReactorFactory, Alert\Future, Amp\Dispatcher;

MyTask extends \Stackable {
    public function run() {
        // Executed when passed to a worker
        return strlen('zanzibar');
    }
}

$reactor = (new ReactorFactory)->select();
$reactor->run(function() use ($reactor) {
    $dispatcher = new Dispatcher($reactor);

    // Using call() to dispatch strlen('zanzibar')
    $future = $dispatcher->call('strlen', 'zanzibar');
    $future->onComplete(function(Future $f) {
        assert($f->succeeded());
        assert($f->getValue() === 8);
    });

    // Using execute() to dispatch strlen('zanzibar')
    $future = $dispatcher->execute(new MyTask);
    $future->onComplete(function(Future $f) use ($reactor) {
        assert($f->succeeded());
        assert($f->getValue() === 8);
        $reactor->stop();
    });
});
```

[pthreads-stackables]: http://us1.php.net/manual/en/class.stackable.php "pthreads Stackable"



#### Magic Task Dispatch

`Dispatcher` implementations delegate the magic `__invoke` function to the
`Dispatcher::execute()` method. This provides a simple shortcut method for `execute()` calls:

```php
<?php
use Alert\ReactorFactory, Alert\Future, Amp\Dispatcher;

class MyTask extends \Stackable {
    public function run() {
        // do something here
    }
}

$reactor = (new ReactorFactory)->select();
$reactor->run(function() use ($reactor) {
    $dispatcher = new Dispatcher($reactor);
    $future = $dispatcher(new MyTask);
    $future->onComplete(function(Future $f) use ($reactor) {
        $reactor->stop();
    });
});
```


#### Class Autoloading

There is no way for pthreads workers to inherit global autoload settings. As a result, if calls
or task executions require class autoloading users must make provisions to register autoload
functions in workers prior to dispatching tasks. This presents the problem of re-registering these
settings each time a worker thread is respawned. Amp resolves this issue by allowing applications to
register `Stackable` tasks to send all worker threads when spawned.

Consider the following example in which we define our own `Stackable` autoload task and register it
for inclusion when workers are spawned via the `"onWorkerStart"` option:

```php
<?php
use Alert\ReactorFactory, Amp\Dispatcher;

class MyAutoloadTask extends \Stackable {
    public function run() {
        spl_autoload_register(function($class) {
            if (0 === strpos($class, 'MyNamespace\\')) {
                $class = str_replace('\\', '/', $class);
                $file = __DIR__ . "/src/$class.php";
                if (file_exists($file)) {
                    require $file;
                }
            }
        });
    }
}

$reactor = (new ReactorFactory)->select();
$dispatcher = new Dispatcher($reactor);
$dispatcher->setOption(Dispatcher::OPT_ON_WORKER_TASK, new MyAutoloadTask);
```

Now all our worker threads register class autoloaders prior to receiving tasks or calls.
