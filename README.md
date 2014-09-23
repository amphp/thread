Thread
======

The `Amp\Thread` library parallelizes synchronous PHP function calls to worker thread pools in
non-blocking applications. The library dispatches blocking calls to worker threads where they can
execute in parallel and returns results asynchronously upon completion.

**Problem Domain**

PHP has a vast catalog of synchronous libraries and extensions but it's generally difficult to
find libs for use inside non-blocking event loops. Beyond this limitation there are common tasks
(like filesystem IO) which don't play nice with the non-blocking paradigm. Amp exposes threaded
concurrency in a non-blocking way to execute discrete tasks in worker threads.

> **NOTE:** This library *is not* intended for use in PHP web SAPI environments. It doesn't make much
sense to fire up a thread pool and socket streams for inter-thread communication on every request
in a web SAPI environment. `Amp\Thread` is designed for use in CLI applications.

**Example**

```php
<?php

function slowAddition($x, $y) {
    sleep(1);
    return $x + $y;
}

try {
    $dispatcher = new Amp\Thread\Dispatcher;

    $a = $dispatcher->call('slowAddition', 1, 5);
    $b = $dispatcher->call('slowAddition', 10, 10);
    $c = $dispatcher->call('slowAddition', 11, 31);

    // Combine these three promises into a single promise that
    // resolves when all of the individual operations complete
    $comboPromise = Amp\all([$a, $b, $c]);

    // Our three calls will complete in one second instead of
    // three because they all run at the same time
    list($a, $b, $c) = $comboPromise->wait();
    var_dump($a, $b, $c);

    /*
    int(6)
    int(20)
    int(42)
    */

} catch (Exception $e) {
    printf("Something went wrong:\n\n%s\n", $e->getMessage());
}
```



### Project Goals

* Expose threaded multiprocessing inside event-driven non-blocking applications;
* Build all components using [SOLID][solid], readable and unit-tested code.


### Requirements

* [PHP 5.5+][php-net] You'll need PHP.
* [pecl/pthreads][pthreads] The pthreads extension ([windows .DLLs here][win-pthreads-dlls])
* [amphp][amp] The amp async multitasking framework

[php-net]: http://php.net "php.net"
[pthreads]: http://pecl.php.net/package/pthreads "pthreads"
[solid]: http://en.wikipedia.org/wiki/SOLID_(object-oriented_design) "S.O.L.I.D."
[amp]: https://github.com/amphp/amp "Asynchronous Multitasking PHP: Hypertext Preprocessor"
[win-pthreads-dlls]: http://windows.php.net/downloads/pecl/releases/pthreads/ "pthreads Windows DLLs"

### Installation

```bash
$ git clone git@github.com:amphp/thread.git
$ cd thread
$ composer install
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
* [Parameters and Returns](#parameters-and-returns)
* [Task Timeouts](#task-timeouts)
* [Pool Size](#pool-size)
* [Thread Execution Limits](#thread-execution-limits)
* [Pthreads Context Flags](#pthreads-context-flags)

**Advanced Usage**

* [Threaded Tasks](#threaded-tasks)
* [Task Progress Updates](#task-progress-updates)
* [Magic Tasks](#magic-tasks)
* [Class Autoloading and Composer](#class-autoloading-and-composer)
* [Naive Wait Parallelization](#naive-wait-parallelization)
* [Parallelization Combinators](#parallelization-combinators)



### Intro

#### Event Loop Basics

> **NOTE:** Because threads use `Amp` concurrency primitives it's possible to execute tasks in
> parallel with zero knowledge of event loops. You can find out more in the
> [Naive Wait Parallelization](#naive-wait-parallelization) and
> [Parallelization Combinators](#parallelization-combinators) sections.

Executing code inside an event loop allows us to use non-blocking libraries to perform multiple IO
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
> scheduler. The Amp dispatcher uses the [`Amp`][amp] event reactor for scheduling.

Because Amp executes inside an event loop, you'll see all of the following examples create a new
event reactor instance to kick things off. Once the event reactor is started it assumes program
control and *will not* return control until your application calls `Reactor::stop()`.

> **Learn more about the [Amp event reactor](https://github.com/amphp/amp).**



### Basic Usage

#### Basic Calls

The simplest way to use the thread library is to dispatch calls to global functions:

```php
<?php
// Everything happens inside an event reactor loop
(new Amp\NativeReactor)->run(function($reactor) {

    // Create our task dispatcher
    $dispatcher = new Amp\Thread\Dispatcher($reactor);

    try {
        // Invoke strlen('zanzibar') in a worker thread.
        // Yield the resulting Promise to avoid callback hell.
        $result = (yield $dispatcher->call('strlen', 'zanzibar!'));
        printf("Woot! strlen('zanzibar') === %d", $result);
    } catch (Exception $e) {
        printf("Something went terribly wrong: %s\n", $e);
    } finally {
        // Stop the event loop so we don't sit around forever
        // after our result comes back
        $reactor->stop();
    }
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
(new Amp\NativeReactor)->run(function($reactor) {
    try {
        $dispatcher = new Amp\Thread\Dispatcher($reactor);
        $str = (yield $dispatcher->call('file_get_contents', '/path/to/file'));
        var_dump($str);
    } catch (Exception $e) {
        printf("Something went terribly wrong: %s\n", $e->getMessage());
    } finally {
        $reactor->stop();
    }
});
```

The above code retrieves the contents of the file at `/path/to/file` in a worker thread and dumps
the result in our main thread upon completion.


#### Userland Functions

We aren't limited to native functions. The `Amp\Thread\Dispatcher` can also dispatch calls to userland
functions ...

```php
<?php
function myMultiply($x, $y) {
    return $x * $y;
}

(new Amp\NativeReactor)->run(function($reactor) {
    try {
        $dispatcher = new Amp\Thread\Dispatcher($reactor);
        var_dump(yield $dispatcher->call('myMultiply', 6, 7));
    } catch (Exception $e) {
        printf("Something went terribly wrong: %s\n", $e->getMessage());
    } finally {
        $reactor->stop();
    }
});
```

The above code results in the following output:

```
int(42)
```


#### Static Methods

The `Dispatcher::call()` method can accept any callable string, so we aren't limited to function
names. We can also dispatch calls to static class methods:

```php
<?php
class MyMultiplier {
    public static function multiply($x, $y) {
        return $x * $y;
    }
}

Amp\run(function() {
    try {
        $dispatcher = new Amp\Thread\Dispatcher;
        var_dump(yield $dispatcher->call('MyMultiplier::multiply', 6, 7));
    } catch (Exception $e) {
        printf("Something went terribly wrong: %s\n", $e->getMessage());
    } finally {
        Amp\stop();
    }
});
```

The above code results in the following output:

```
int(42)
```

> **IMPORTANT:** In this example we've hardcoded the `MyMultiplier` class definition in the code.
> There is *no* class autoloading employed. There is no way for `pecl/pthreads` to inherit globally
> registered autoloaders from the main thread. If you require autoloading in your worker threads
> you *MUST* dispatch a `Threaded` task to define autoloader function(s) in your workers as
> demonstrated in the [Class Autoloading](#class-autoloading-and-composer) section of this guide.


#### Magic Calls

Dispatchers take advantage of the magic `__call()` method to simplify calls to functions in the global
namespace. Consider:

```php
<?php
Amp\run(function() {
    try {
        $dispatcher = new Amp\Thread\Dispatcher;
        $fileHandle = (yield $dispatcher->fopen('/path/to/file', 'r'));
        var_dump($fileHandle);
    } catch (Exception $e) {
        printf("Something went wrong: %s", $error->getMessage());
    } finally {
        Amp\stop();
    };
});
```

The above code opens a read-only file handle to the specified file and returns the result in
our main thread upon completion.


#### Error Handling

You may have noticed that our examples to this point have not returned results directly. Instead,
they return an instance of `Amp\Promise`. These monadic placeholder objects allow us to distinguish
between successful execution results from our worker threads and errors. When using generators to
yield control any exceptions encountered will be thrown back into the generator and must be caught
or they will bubble up and crash our program.

**Uncaught Exception**

```php
<?php

function myThrowingFunction() {
    throw new \RuntimeException('oh noes!!!');
}

Amp\run(function() {
    try {
        $dispatcher = new Amp\Thread\Dispatcher;
        var_dump(yield $dispatcher->myThrowingFunction());
    } catch (Exception $e) {
        printf("Function threw %s as expected: %s\n", get_class($e), $e->getMessage());
    } finally {
        Amp\stop();
    }
});
```

**Fatal Error**

In the following example we purposefully do something that will generate a fatal error in our
worker thread. Our dispatcher seamlessly recovers from the fatal condition on its own; there is no
need to restart the thread pool and our main thread reports the error as if it were a normal exception.

```php
<?php

function myFatalFunction() {
    $nonexistentObject->nonexistentMethod(); // fatal
}

Amp\run(function() {
    try {
        $dispatcher = new Amp\Thread\Dispatcher;
        (yield $dispatcher->myFatalFunction());
    } catch (Exception $e) {
        printf("Function threw %s as expected: %s\n", get_class($e), $e->getMessage());
    } finally {
        Amp\stop();
    }
});
```

#### Parameters and Returns

While Amp tries as much as possible to hide the implementation details of the underlying pthreads
extension, your parallel operations are still bound by the constraints and limitations of pthreads.
The primary limitations arising from this condition center around the raw parameters and return values
in your parallelized calls. The main thing you need to remember is:

> **IMPORTANT:** All individual parameters and returns (with the exception of resources) will be
> serialized by pthreads for transport between the main thread and worker threads.

This condition cannot be overemphasized. The individual parameters MUST either be capable of
surviving serialization or they MUST be resource primitives. There is one major pitfall with this
constraint:

> **GOTCHYA:** Be careful not to wrap resources inside arrays as the array will be serialized and
> the individual resource elements will not survive the trip over a thread boundary.

As long as you pass/return resource parameters directly (as opposed to wrapped inside arrays
or objects) your parallel functions should "just work."


#### Task Timeouts

> **NOTE:** Relying on timeouts is almost always a poor design decision. You're much better served
> to solve the underlying problem that causes slow execution in your dispatched calls/tasks.

Amp automatically times out tasks exceeding the (configurable) maximum allowed run-time. We can
customize this setting as shown in the following example:

```php
<?php

use Amp\Thread\Dispatcher;
use Amp\Thread\TimeoutException;

Amp\run(function() {
    $dispatcher = new Dispatcher;

    // Only use one worker so our thread pool acts like a FIFO job queue
    $dispatcher->setOption(Dispatcher::OPT_POOL_SIZE_MAX, 1);

    // Limit per-call execution time to 2 seconds
    $dispatcher->setOption(Dispatcher::OPT_TASK_TIMEOUT, 2);

    try {
        // This task will timeout after two seconds
        (yield $dispatcher->sleep(9999));
    } catch (TimeoutException $e) {
        printf("Our task timed out: %s\n", $e->getMessage());
    } finally {
        Amp\stop();
    }
});
```

#### Pool Size

You may have noticed that in the above timeout example we explicity set a max pool size option.
The effect of this setting should be obvious: it controls how many worker threads we spawn to handle
task dispatches. An example:

```php
<?php

$reactor = new Amp\NativeReactor;
$dispatcher = new Amp\Thread\Dispatcher($reactor);
$dispatcher->setOption(Amp\Thread\Dispatcher::OPT_POOL_SIZE_MAX, 16);
```

By default the `Amp\Thread\Dispatcher` will only spawn a single worker thread. Each time a call is
dispatched a new thread will be spawned if all existing workers in the pool are busy (subject to the
configured max size). The default `OPT_POOL_SIZE_MAX` setting is 8. If no workers are available and
the pool size is maxed calls are queued and dispatched as workers become available.

> **NOTE::** Idle worker threads are periodically unloaded to avoid holding open threads
> unnecessarily.

Dispatchers keep a minimum number of worker threads open at all times (even when idle). By default
the minimum number of threads kept open is 1. This value may be changed as follows:

```php
<?php

$reactor = new Amp\NativeReactor;
$dispatcher = new Amp\Thread\Dispatcher($reactor);
$dispatcher->setOption(Amp\Thread\Dispatcher::OPT_POOL_SIZE_MIN, 4);
```


#### Thread Execution Limits

In theory we shouldn't have to worry about sloppy code or extensions playing fast and loose with
memory resources. However in the real world this may not always be an option. Amp makes provision
for these scenarios by exposing a configurable limit setting to control how many dispatches a
worker thread will accept before being respawned to clean up any outstanding garbage. If you wish
to modify this setting simply set the relevant option:

```php
<?php

use Amp\Thread\Dispatcher;
$dispatcher = Dispatcher;
$dispatcher->setOption(Dispatcher::OPT_EXEC_LIMIT, 1024); // 1024 is the default
```

Users who wish to remove the execution limit you may set the value to `-1` as shown here:

```php
<?php

use Amp\Thread\Dispatcher;
$dispatcher = Dispatcher;
$dispatcher->setOption(Dispatcher::OPT_EXEC_LIMIT, -1);
```


#### Pthreads Context Flags

Users can control the context inheritance mask used to start worker threads by setting thread start
flags as shown here:

```php
<?php

use Amp\Thread\Dispatcher;
$dispatcher = new Dispatcher;
$dispatcher->setOption(Dispatcher::OPT_THREAD_FLAGS, PTHREADS_INHERIT_NONE);
```

The full list of available flags can be found in the relevant [pthreads documentation page][pthreads-flags].

[pthreads-flags]: php.net/manual/en/pthreads.constants.php "pthreads flags"







### Advanced Usage

#### Threaded Tasks

While Amp abstracts much of the underlying pthreads functionality there are times when low-level
access is useful. For these scenarios Amp allows the specification of "tasks" extending pthreads
[`Threaded`][pthreads-threaded]. Threadeds allow users to specify arbitrary code in the main
thread and use it for execution in worker threads.

> **NOTE:** All `Threaded` classes MUST (per pthreads) specify the abstract `Threaded::run()`
> method. Your `Threaded` object's `run()` method is the routine that will execute in the worker thread.
> In order to avoid errors your `Threaded::run()` must call the worker thread's `resolve()` method
> as shown in the example below. This is how Amp knows what to return from the threaded task.

Instances of your custom `Threaded` may then be passed to the `Dispatcher::execute()` method
for processing.

```php
<?php
MyTask extends \Threaded {
    public function run() {
        $result = strlen('zanzibar');

        // Custom tasks must register their results using either
        // Amp\Thread\Thread::SUCCESS or Amp\Thread\Thread::FAILURE:
        $this->worker->resolve(Amp\Thread\Thread::SUCCESS, $result);
    }
}

Amp\run(function() {
    try {
        $dispatcher = new Amp\Thread\Dispatcher;
        $len = (yield $dispatcher->execute(new MyTask)); // <-- our custom task
        var_dump($len);
    } catch (Exception $e) {
        printf("Something went terribly wrong: %s\n", $e->getMessage());
    } finally {
        Amp\stop();
    }
});
```

[pthreads-threaded]: http://us1.php.net/manual/en/class.threaded.php "pthreads Threaded"


#### Task Progress Updates

Because the `Amp` concurrency primitives support incremental progress updates we can expose this
functionality in our custom `Threaded` tasks. In the same way we use the worker's `resolve()`
method to indicate task completion we can use `progress()` to notify our main thread incrementally
before the task actually completes. Below we show how to send/receive progress updates in our
threaded tasks.

```php
<?php

MyIncrementalTask extends \Threaded {
    public function run() {
        $this->worker->progress(1);
        sleep(1);
        $this->worker->progress(2);
        sleep(1);
        $this->worker->progress(3);
        sleep(1);
        $this->worker->resolve(Amp\Thread::SUCCESS, 42);
    }
}

$dispatcher = new Amp\Thread\Dispatcher;
$task = new MyIncrementalTask;
$promise = $dispatcher->execute($task);

// Watch for progress updates from our task
$promise->watch(function($updateData) {
    printf("Progress update: %s\n", $updateData);
});

// Wait for the final task result
printf("Final task result: %s\n", $promise->wait());
```

The above code will output the following:

```
Progress update: 1
Progress update: 2
Progress update: 3
Final task result: 42
```



#### Magic Task Dispatch

`Dispatcher` implementations delegate the magic `__invoke` function to the
`Dispatcher::execute()` method. This provides a simple shortcut method for `execute()` calls:

```php
<?php

class MyTask extends \Threaded {
    public function run() {
        // do something here
    }
}

(new Amp\NativeReactor)->run(function($reactor) {
    $dispatcher = new Amp\Thread\Dispatcher($reactor);
    $promise = $dispatcher(new MyTask);
    $promise->when(function($error, $result) use ($reactor) {
        assert($error === null);
        assert($result === 8);
        $reactor->stop();
    });
});
```


#### Class Autoloading and Composer

There is no way for pthreads workers to inherit global autoload settings. As a result, if calls
or task executions require class autoloading users must make provisions to register autoload
functions in workers prior to dispatching tasks. This presents the problem of re-registering these
settings each time a worker thread is respawned. Amp resolves this issue by allowing applications to
register `Threaded` tasks to send workers whenever they're spawned.

Consider the following example in which we define our own autoload task and register it for
inclusion when workers are spawned:

```php
<?php

class MyAutoloadTask extends \Threaded {
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

$reactor = new Amp\NativeReactor;
$dispatcher = new Amp\Thread\Dispatcher($reactor);
$dispatcher->addStartTask(new MyAutoloadTask);
```

Now all our worker threads register class autoloaders prior to receiving tasks or calls. Note that
"start tasks" are stored in an `SplObjectStorage` instance, so repeatedly adding the same instance
will have no effect. After adding a start task you may also remove it in the future as shown here:

```php
$myStartTask = new MyAutoloadTask;

$reactor = new Amp\NativeReactor;
$dispatcher = new Amp\Thread\Dispatcher($reactor);
$dispatcher->addStartTask($myStartTask);

// ... //

$dispatcher->removeStartTask($myStartTask);
```

**Composer**

Using a generated autoloader from composer is no different from registering any other autoloader:

```php
<?php

class MyComposerAutoloadTask extends \Threaded {
    public function run() {
        require '/path/to/vendor/autoload.php';
    }
}

$dispatcher = new Amp\Thread\Dispatcher;
$dispatcher->addStartTask(new MyComposerAutoloadTask);
```

That's it!


#### Naive Wait Parallelization

Because threads use the `Amp` concurrency primitives library, users don't actually need any
understanding of the underlying non-blocking event loop to execute Amp tasks in parallel. By calling
`wait()` on any promise we can block code execution indefinitely until the promised value resolves:

```php
<?php

try {
    $dispatcher = new Amp\Thread\Dispatcher;

    // Dispatch a threaded task
    $promise = $dispatcher->call('strlen', 'zanzibar');

    // Synchronously Block until the promise resolves
    $result = $promise->wait();

    var_dump($result); // int(8)

} catch (Exception $e) {
    printf("Something went wrong:\n\n%s\n", $e->getMessage());
}
```

#### Parallelization Combinators

We can parallelize mutliple threaded operations by using `Amp` combinators:

```php
<?php

try {
    $dispatcher = new Amp\Thread\Dispatcher;

    $a = $dispatcher->call('sleep', 1);
    $b = $dispatcher->call('sleep', 1);
    $c = $dispatcher->call('sleep', 1);

    // Combine these three promises into a single promise that
    // resolves when all of the individual operations complete
    $comboPromise = Amp\all([$a, $b, $c]);

    // Our three sleep() operations will complete in one second
    // because they all run at the same time!
    $comboPromise->wait();

} catch (Exception $e) {
    printf("Something went wrong:\n\n%s\n", $e->getMessage());
}
```

Combinator return values are also easily accessible. Consider the following example where we list
the individual results from our parallel calls:

```php
<?php

function add($x, $y) {
    return $x + $y;
}

try {
    $dispatcher = new Amp\Thread\Dispatcher;

    $a = $dispatcher->call('add', 1, 2);
    $b = $dispatcher->call('add', 10, 32);
    $c = $dispatcher->call('add', 5, 7);

    // Combine these three promises into a single promise that
    // resolves when all of the individual operations complete
    $comboPromise = Amp\all([$a, $b, $c]);

    // Wait for the three parallel operations to complete
    list($a, $b, $c) = $comboPromise->wait();
    var_dump($a, $b, $c);

    /*
    int(3)
    int(42)
    int(12)
    */

} catch (Exception $e) {
    printf("Something went wrong:\n\n%s\n", $e->getMessage());
}
```
