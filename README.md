AMP: Async Multiprocessing in PHP (5.4+)
----------------------------------------

Amp parallelizes synchronous PHP function calls to worker thread pools in non-blocking applications.
Its goal is to expose the full retinue of blocking PHP code to non-blocking environments. The library
allows developers to write synchronous code which is then dispatched to worker threads where it is
executed asynchronously with results returned upon completion. This functionality is exposed in an
OS-agnostic manner via the [pthreads extension][pthreads].

**Problem Domain**

PHP has a broad catalog of synchronous libraries and extensions. However, it's often difficult to
find non-blocking libs to use inside our event loop. Beyond this limitation, there are common tasks
(like filesystem IO) which don't play nice with the non-blocking paradigm. Unfortunately, threading
is an altogether different approach to concurrency from that used in non-blocking applications.
Amp's goal is to seemlessly expose access to threaded concurrency inside non-blocking PHP
applications.


### Project Goals

* Expose threaded multiprocessing inside event-driven non-blocking applications;
* Build all components using [SOLID][solid], readable and unit-tested code.


### Requirements

* [PHP 5.4+][php-net] You'll need PHP, of course.
* [`ext/pthreads`][pthreads] The pthreads extension ([windows .DLLs here][win-pthreads-dlls])
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
* [Task Cancellation](#task-cancellation)
* [Task Timeouts](#task-timeouts)
* [Pool Size](#pool-size)
* [Thread Execution Limits](#thread-execution-limits)
* [Pthreads Context Flags](#pthreads-context-flags)

**Advanced Usage**

* [Stackable Tasks](#stackable-tasks)
* [Task Priority](#task-priority)
* [Magic Tasks](#magic-tasks)
* [Fire and Forget](#fire-and-forget)
* [Class Autoloading](#class-autoloading)




### Intro

##### Event Loop Basics

Executing code inside an event loop allows us to use non-blocking libraries to perform many IO
operations at the same time. Instead of waiting for each individual operation to complete the event
loop assumes program flow and informs us when our tasks finish or actionable events occur. This
paradigm allows our program to execute code when it would otherwise be idling to wait for slow IO
operations to return. The non-blocking approach is particularly useful for scalable network
applications and servers where the naive thread-per-connection approach is untenable.

Unfortunately, robust applications generally require synchronous functionality and/or filesystem
operations that can't utilize non-blocking descriptors. Amp was created to provide non-blocking
applications access to the full range of synchronous PHP functionality without blocking the main
event loop.

> **NOTE:** It's critical that any non-blocking libs in your application use the *same* event loop
> scheduler. The Amp dispatcher uses the [`Alert`][alert] event reactor for scheduling.

Because Amp executes inside an event loop, you'll see all of the following examples create a new
event reactor instance to kick things off. Once the event reactor is started it assumes program
control and *will not* return this control until your application calls `Reactor::stop()`.



### Basic Usage


##### Basic Calls

The simplest way to use Amp is to dispatch calls to global functions:

```php
<?php
use Alert\ReactorFactory, Amp\PthreadsDispatcher;

// Get an event reactor
$reactor = (new ReactorFactory)->select();

// Start the event loop and tell it to execute this closure immediately
$reactor->run(function() use ($reactor) {

    // Create our PthreadsDispatcher using the event reactor
    $dispatcher = new PthreadsDispatcher($reactor);

    // Invoke strlen('zanzibar') in a worker thread and
    // notify our callback when the result comes back
    $dispatcher->call('strlen', 'zanzibar!', function($result) use ($reactor) {
        printf("Woot! strlen('zanzibar) === %d", $result->getResult());
        $reactor->stop();
    });

});
```

The above example will output the following to our console:

```
Woot! strlen('zanzibar') === 8
```

Obviously `strlen` is a spurious use of a thread. Remember that it only makes sense to dispatch work
to a thread if the processing overhead of sending the call and receiving the result is less expensive
than the actual time of the call. A more useful example demonstrates retrieving a filesystem resource:

```php
<?php
use Alert\ReactorFactory, Amp\PthreadsDispatcher;

$reactor = (new ReactorFactory)->select();
$reactor->run(function() use ($reactor) {
    $dispatcher = new PthreadsDispatcher($reactor);
    $dispatcher->call('file_get_contents', '/path/to/file', function($result) use ($reactor) {
        var_dump($result->getResult());
        $reactor->stop();
    });
});
```

The above code retrieves the contents of the file at `/path/to/file` and var_dumps the result in
our main thread upon completion.


##### Userland Functions

We aren't limited to native functions. The `Amp\PthreadsDispatcher` can dispatch calls to userland
functions just as easily. Consider:

```php
<?php
use Alert\ReactorFactory, Amp\PthreadsDispatcher;

function multiply($x, $y) {
    return $x * $y;
}

$reactor = (new ReactorFactory)->select();
$reactor->run(function() use ($reactor) {
    $dispatcher = new PthreadsDispatcher($reactor);
    $dispatcher->call('multiply', 6, 7, function($result) use ($reactor) {
        var_dump($result->getResult());
        $reactor->stop();
    });
});
```

The above code results in the following output:

```
int(42)
```


##### Static Methods

The `Dispatcher::call` method can handle any callable string, so we aren't limited to function
names. We can also dispatch calls to static class methods:

```php
<?php
use Alert\ReactorFactory, Amp\PthreadsDispatcher;

class MyMultiplier {
    public static function multiply($x, $y) {
        return $x * $y;
    }
}

$reactor = (new ReactorFactory)->select();
$reactor->run(function() use ($reactor) {
    $dispatcher = new PthreadsDispatcher($reactor);
    $dispatcher->call('MyMultiplier::multiply', 6, 7, function($result) use ($reactor) {
        var_dump($result->getResult());
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


##### Magic Calls

Dispatchers take advantage of the magic `__call()` method to simply calls to functions in the global
namespace. Consider:

```php
<?php
use Alert\ReactorFactory, Amp\PthreadsDispatcher;

$reactor = (new ReactorFactory)->select();
$reactor->run(function() use ($reactor) {
    $dispatcher = new PthreadsDispatcher($reactor);
    $dispatcher->fopen('/path/to/file', 'r' function($result) use ($reactor) {
        $fileHandle = $result->getResult();
        $reactor->stop();
    });
});
```

The above code opens a read-only file handle to the specified file and returns the result in
our main thread upon completion.

> **NOTE:** This example successfully returns a resource from the worker thread. However, resources
> are very finicky with pthreads. To learn more about resource passing please read the
> [Pthreads Pitfalls](#pthreads-pitfalls) section.


##### Error Handling

You should have noticed that our examples to this point have not returned results directly. Instead,
they return an instance of `Amp\DispatcherResult`. These wrapper objects allow us to distinguish
between successful results and errors. The most important thing to remember is this:

> Calling `DispatcherResult::getResult()` will throw in the main thread if execution encountered a
> fatal error inside the worker thread.

This behavior makes it impossible to ignore execution failures. Of course, we can easily determine
if a call failed using the `DispatcherResult::succeeded()` and `DispatcherResult::failed()` methods.
Consider the following examples ...

**Uncaught Exception**

```php
<?php
use Alert\ReactorFactory, Amp\PthreadsDispatcher;

function myThrowingFunction() {
    throw new RuntimeException('oh noes!!!');
}

$reactor = (new ReactorFactory)->select();
$reactor->run(function() use ($reactor) {
    $dispatcher = new PthreadsDispatcher($reactor);
    $dispatcher->myThrowingFunction(function($result) use ($reactor) {
        var_dump($result->failed()); // bool(true)
        var_dump($result->getError() instanceof Exception); // bool(true)
        try {
            $var = $result->getResult();
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
restart the thread pool. Our main thread seamlessly continues execution:

```php
<?php
use Alert\ReactorFactory, Amp\PthreadsDispatcher;

function myFatalFunction() {
    $var = $nonexistentObject->nonexistentMethod();
}

$reactor = (new ReactorFactory)->select();
$reactor->run(function() use ($reactor) {
    $dispatcher = new PthreadsDispatcher($reactor);
    $dispatcher->myFatalFunction(function($result) use ($reactor) {
        var_dump($result->failed()); // bool(true)
        echo $result->getError(); // view the error traceback
        $reactor->stop();
    });
});
```

##### Task Cancellation

All calls return a unique integer task ID referencing the call. We can use this ID to cancel a
dispatched call at any time prior to completion via `Dispatcher::cancel()`. If a call is cancelled
it *WILL NOT* have its callback invoked. The Dispatcher will simply behave as if the call had never
been made. Consider:

```php
<?php
use Alert\ReactorFactory, Amp\PthreadsDispatcher;

function multiply($x, $y) {
    return $x * $y;
}

$reactor = (new ReactorFactory)->select();

$reactor->run(function() use ($reactor) {
    $dispatcher = new PthreadsDispatcher($reactor);
    $dispatcher->setOption('poolSize', 1);

    // Call a sleep function that takes forever to return
    $slowTaskId = $dispatcher->sleep(9999, function(){});

    // Queue another function behind the sleep() call
    $dispatcher->multiply(6, 7, function($result) use ($reactor) {
        var_dump($result->getResult()); // int(42)
        $reactor->stop();
    });

    // Cancel the slow task so that the other tasks
    // queued behind it can execute:
    $dispatcher->cancel($slowTaskId);
});
```

##### Task Timeouts

> **NOTE:** Relying on timeouts is almost always a poor design decision. You're much better served
> to solve the underlying problem that causes slow execution in your dispatched calls/tasks. This
> timeout functionality should primarily be used in server environments where very long-running
> tasks can become a potential DoS vector.

In the example on [Task Cancellation](#task-cancellation) we demonstrated how to cancel a
long-running task. Manual timeout management is not necessary, however, as the `Amp\PthreadsDispatcher`
automatically times out tasks exceeding the maximum allowed run-time. We can configure this setting
as shown in the following example:

```php
<?php
use Alert\ReactorFactory, Amp\PthreadsDispatcher;

$reactor = (new ReactorFactory)->select();
$reactor->run(function() use ($reactor) {
    $dispatcher = new PthreadsDispatcher($reactor);

    // Only use one worker so our thread pool acts like a FIFO job queue
    $dispatcher->setOption('poolSize', 1);

    // Limit per-call execution time to 2 seconds
    $dispatcher->setOption('taskTimeout', 2);

    // This function will be timed out after two seconds
    $slowTaskId = $dispatcher->sleep(9999, function($result) {
        var_dump($result->failed()); // bool(true)
    });

    // Queue another function behind the sleep() call
    $dispatcher->multiply(6, 7, function($result) use ($reactor) {
        var_dump($result->getResult()); // int(42)
        $reactor->stop();
    });
});
```

##### Pool Size

You may have noticed that in some of our previous examples we've explicity set a `"poolSize"` option.
The effect of this setting should be obvious: it controls how many worker threads we spawn to handle
task dispatches. An example:

```php
<?php
use Alert\ReactorFactory, Amp\PthreadsDispatcher;

$reactor = (new ReactorFactory)->select();
$dispatcher = new PthreadsDispatcher($reactor);
$dispatcher->setOption('poolSize', 16);
```

By default the `Amp\PthreadsDispatcher` will only spawn one worker thread. In order to spawn more
this option must be assigned prior to calling the dispatcher's `start()` method (or dispatching a
call as this automatically calls `start()` to populate the thread pool). Setting this option after
the Dispatcher has started will have no effect.

> **NOTE:** Like the other dispatcher options, `"poolSize"` is NOT case-sensitive.


##### Thread Execution Limits

In theory we shouldn't have to worry about sloppy code or extensions playing fast and loose with
memory resources. However in the real world this may not always be an option. Amp makes provision
for these scenarios by exposing a configurable limit setting to control how many dispatches a
worker thread will accept before being respawned to clean up any outstanding garbage. If you wish
to modify this setting simply set the relevant option:

```php
<?php
use Alert\ReactorFactory, Amp\PthreadsDispatcher;

$reactor = (new ReactorFactory)->select();
$dispatcher = new PthreadsDispatcher($reactor);
$dispatcher->setOption('executionLimit', 1024); // 1024 is the default
```

Users who wish to remove the execution limit you may set the value to `-1` as shown here:

```php
<?php
use Alert\ReactorFactory, Amp\PthreadsDispatcher;

$reactor = (new ReactorFactory)->select();
$dispatcher = new PthreadsDispatcher($reactor);
$dispatcher->setOption('executionLimit', -1);
```


##### Pthreads Context Flags

Users can control the context inheritance mask used to start worker threads by setting the
`"threadStartFlags"` option as shown here:

```php
<?php
use Alert\ReactorFactory, Amp\PthreadsDispatcher;

$reactor = (new ReactorFactory)->select();
$dispatcher = new PthreadsDispatcher($reactor);
$dispatcher->setOption('threadStartFlags', PTHREADS_INHERIT_NONE);
```

The full list of available flags can be found in the relevant [pthreads documentation page][pthreads-flags].

[pthreads-flags]: php.net/manual/en/pthreads.constants.php "pthreads flags"


### Advanced Usage

##### Stackable Tasks

While Amp abstracts much of the underlying pthreads functionality there are times when low-level
access is useful. For these scenarios Amp allows the specification of "tasks" extending pthreads
[`Stackable`][pthreads-stackables]. Stackables allow users to specify arbitrary code in the main
thread and use it for execution in worker threads.

> **NOTE:**All `Stackable` classes MUST (per pthreads) specify the abstract `Stackable::run()` method

Instances of your custom `Stackable` may then be passed to the `PthreadsDispatcher::execute()` method
for processing.

```php
<?php
use Alert\ReactorFactory, Amp\PthreadsDispatcher;

MyTask extends \Stackable {
    public function run() {
        // Executed when passed to a worker
        return strlen('zanzibar');
    }
}

$reactor = (new ReactorFactory)->select();
$reactor->run(function() use ($reactor) {
    $dispatcher = new PthreadsDispatcher($reactor);

    // Using call() to dispatch strlen('zanzibar')
    $dispatcher->call('strlen', 'zanzibar', function($result) {
        assert($result->getResult === 8);
    });

    // Using execute() to dispatch strlen('zanzibar')
    $dispatcher->execute(new MyTask, function($result) use ($reactor) {
        assert($result->getResult === 8);
        $reactor->stop();
    });
});
```

[pthreads-stackables]: http://us1.php.net/manual/en/class.stackable.php "pthreads Stackable"


##### Task Priority

Amp stores tasks in a priority queue allowing applications to prioritize task execution order.
Priority assignment is only available for task execution (as opposed to `Dispatcher::call()`).

All priorities are measured on a scale of 1-100. Lower values are executed before higher values. So
in the following example the `MyCriticalTask` will be dequeued for processing prior to the
`MyUnimportantTask` if there are more tasks to process than there are available worker threads to
execute those tasks.

```
<?php
use Alert\ReactorFactory, Amp\PthreadsDispatcher;

class MyUnimportantTask extends \Stackable {
    public function run() {
        // do something low-priority here
    }
}

class MyCriticalTask extends \Stackable {
    public function run() {
        // do something VERY important here
    }
}

$reactor = (new ReactorFactory)->select();

$reactor->run(function() use ($reactor) {
    $dispatcher = new PthreadsDispatcher($reactor);
    $dispatcher->execute(new MyUnimportantTask, $onResult = function($result) {
        // do something with the unimportant result
    }, $priority = 99);
    $dispatcher->execute(new MyCriticalTask, $onResult = function($result) {
        // do something with the critical result
    }, $priority = 10);

    $dispatcher->call('sleep', 1, function() use ($reactor) {
        $reactor->stop();
    });
});
```


##### Magic Task Dispatch

`ThreadDispatcher` implementations delegate the magic `__invoke` function to the
`ThreadDispatcher::execute()` method. This provides a simple shortcut method for `execute()` calls:

```
<?php
use Alert\ReactorFactory, Amp\PthreadsDispatcher;

class MyTask extends \Stackable {
    public function run() {
        // do something here
    }
}


$reactor = (new ReactorFactory)->select();
$reactor->run(function() use ($reactor) {
    $dispatcher = new PthreadsDispatcher($reactor);
    $dispatcher(new MyCriticalTask, $onResult = function($result) use ($reactor) {
        $reactor->stop();
    });
});
```


##### Fire and Forget

Sometimes we don't care whether a task succeeds or fails and we just want to fire it off for
processing. While it's frequently a bad idea to ignore the possibility of failure, there *are* cases
where this behavior makes sense.

To this end `Amp` provides the `PthreadsDispatcher::forget()` method. It works *exactly* the same as
the `PthreadsDispatcher::stack()` method except that it does not notify the caller of success or
failure upon completion. The code is simply executed and forgotten. Tasks dispatched via the
`forget()` method are queued and prioritized in the same way as any other task. The only difference
is the lack of a result callback. Consider:

```php
<?php
use Alert\ReactorFactory, Amp\PthreadsDispatcher;

class MyForgetableTask extends \Stackable {
    public function run() {
        // do something here
    }
}

$reactor = (new ReactorFactory)->select();
$reactor->run(function() use ($reactor) {
    $dispatcher = new PthreadsDispatcher($reactor);
    $dispatcher->forget(new MyForgetableTask);
    $dispatcher->call('sleep', 1, function() use ($reactor) {
        $reactor->stop();
    });
});
```

##### Class Autoloading

There is no way for pthreads workers to inherit global autoload settings. As a result, if calls
or task executions require class autoloading users must make provisions to register autoload
functions in workers prior to dispatching tasks. This presents the problem of re-registering these
settings each time a worker thread is respawned. Amp resolves this issue by allowing applications to
register `Stackable` tasks to send all worker threads when spawned.

Consider the following example in which we define our own `Stackable` autoload task and register it
for inclusion when workers are spawned via the `"onWorkerStart"` option:

```php
<?php
use Alert\ReactorFactory, Amp\PthreadsDispatcher;

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
$dispatcher = new PthreadsDispatcher($reactor);
$dispatcher->setOption('onWorkerStart', new MyAutoloadTask);
```

Now all our worker threads register class autoloaders prior to receiving tasks or calls.