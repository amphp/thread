AMP Job Server Quickstart
=========================

## 1. Get the code

Git is the preferred method for retrieving the AMP source code:

```bash
$ git clone --recursive http://github.com/rdlowrey/Amp.git
```

By specifying the `--recursive` option git will retrieve the necessary [`Alert`][alert-repo]
dependency. This library provides the event/IO reactor underlying AMP's event-driven architecture
and AMP will not function without it.

## 2. Start a job server

Now that we have the code it's time to use it! But before we can dispatch asynchronous calls to an
AMP job server we actually need to *start an AMP job server.* Assuming we've just cloned the
repository via git we can get a help screen by executing the following:

```bash
$ cd Amp/bin
$ php amp.php -h
```

## 3. Job server configuration

This help command (-h, --help) displays basic usage commands for the job server executable. The most
important configuration directives are ...

### `-l, --listen`

The `listen` directive is required. It's used to specify the IP address and port on which the job
server will listen. You can specify an exact address like so:

```bash
$ php amp.php --listen="127.0.0.1:1337"
```

However, it's generally best to use a wildcard IP (`*`) to capture traffic on all IPv4 interfaces:

```bash
$ php amp.php -l=*:1337
```

Servers listening on IPv6 interfaces must enclose IP addresses in brackets as demonstrated below:

```bash
$ php amp.php --listen=[fe80::1]:1337
```

And the wildcard IPv6 version:

```bash
$ php amp.php --l="[::]:1337"
```

### `-i, --include`

The `include` directive is technically optional, but if we don't specify its value the only
functions we'll be able to invoke asynchronously are those compiled into PHP. Obviously, that won't
be very helpful. As a result we'll almost always specify an include file to provision the job server
with userland procedures. Specifying the include file looks like this:

```bash
$ php amp.php --listen="*:1337" --include="/hard/path/to/user/functions.php"
```

##### Include file procedures

AMP allows the remote invocation of two PHP callable types:

- global functions
- static class methods

The logic behind the string-based callable limitation should be obvious: *we have to transmit the
procedures over TCP sockets and process pipes.*

So an include file might be as simple as a single function:

```php
<?php // example amp include file

function doSomeSlowDatabaseCall($arg1, $arg2) {
    // ... make the query here ...
    return $stringResults;
}

```

Alternatively, your include file might be nothing more than a class autoloader script so that when
AMP calls your static procedures it's able to import the relevant classes. Note that because the
worker processes employed by the job server are persistent you can also retain state. In high load
environments you can use this to your advantage to pre-load global resources or database connections
for subsequent reuse inside your RPC-style procedures.

##### Serialization

There is one thing you must always remember regarding your async functions:

> **IMPORTANT:** All arguments and return values **MUST** be serializable. This limitation exists
for the same reason that procedures must be string-based: because the data has to be transferred as
bytes over the wire. AMP is pretty smart, but it will never be able to serialize your MySQL result
resources (for example). It's your job to use arguments and return values that PHP can serialize and
unserialize.

### `-w, --workers`

This option tells the job server how many worker processes to spawn to handle job dispatches. If all
your tasks are CPU-bound it makes sense to only have one worker per CPU core available for
processing. However, the vast majority of use-cases are IO-bound (like database queries). In such
environments it makes sense to spawn however many worker processes you need (within reason) to match
the concurrent load of procedure calls. If no `-w/--workers` option is specified AMP will keep four
worker processes in the pool at all times. To modify this number simply pass the job server the
appropriate switch:

```bash
$ php amp.php --listen="*:1337" --include="/my/functions.php" --workers=16
```

> **NOTE:** If you dynamically load a lot of unnecessary extensions your PHP processes can open
*a lot* of file descriptors. These descriptors must be held open for each worker process. The only
way around this is to not enable lots of extensions you don't actually need. Work on a threaded job
dispatcher is in-progress and hopefully it won't be too long before you'll have the option of
avoiding process overhead in favor of worker threads.

### Other options

The help command will summarize any other options for you. After all, this is supposed to be a
*QUICKSTART* file ...


## 4. Dispatching calls to a job server

At this point you should have a job server up and running. All that's left to do is dispatch your
procedures to the server. AMP utilizes the [`Alert`][alert-repo] event reactors to parallelize
non-blocking socket IO in event driven code. To utilize the built-in `Amp\JobDispatcher` for
asynchronous calls we simply hook up the dispatcher to an event loop and watch the magic happen ...

```php
<?php // Basic async job client example

require '/path/to/Amp/autoload.php'; // <-- adjust for your environment

use Alert\ReactorFactory, Amp\CallResult, Amp\JobDispatcher;

$eventReactor = (new ReactorFactory)->select();
$dispatcher = new JobDispatcher($eventReactor);
$dispatcher->connectToJobServer('127.0.0.1:1337');
$dispatcher->setOption('debug', TRUE);

$completedCallCount = 0;
$onResult = function(CallResult $callResult) use ($eventReactor, &$completedCallCount) {
    if (++$completedCallCount === 3) {
        echo "Done!\n";
        $eventReactor->stop();
    }
};

$reactor->immediately(function() use ($dispatcher, $onResult) {
    $dispatcher->call($onResult, 'sleep', 1); // returns immediately, executes in parallel
    $dispatcher->call($onResult, 'sleep', 1); // returns immediately, executes in parallel
    $dispatcher->call($onResult, 'sleep', 1); // returns immediately, executes in parallel
});

$eventReactor->run();
```

#### So what's going on in that code?

Let's walk through this code and see what's happening step by step:

1. First, we require the class autoloader that comes packaged with AMP. You can use any autoloader
   you like to load AMP classes but the `autoload.php` file is included as a convenience.
2. We alias the class names we'll reference with a `use` statement to make the code a bit cleaner.
3. We create an instance of the `Alert\Reactor` interface. The `Alert\ReactorFactory` class is used
   to auto-select the most performant event reactor for our system.
4. We instantiate the `Amp\JobDispatcher` client class and pass it the event reactor dependency it
   requires to operate. Also, we enable debug output so the dispatcher will tell us what's happening
   in our console while the program runs.
5. We create a callback (`$onResult`) to receive the asynchronous results as they return from the
   `Amp\JobDispatcher` client class. Every time we dispatch an asynchronous call we have to supply
   a callback to receive the `Amp\CallResult` object created by the job dispatcher when the call
   returns. This is necessary because the call is processed *asynchronously* (duh). In this case
   the callback simply keeps track of how many of our parallel function calls have returned so it
   can stop the event reactor (and end the program) once all three calls have been fulfilled.
6. We schedule an event with the reactor to fire immediately when the event loop starts that will
   dispatch our calls to the job server. Why do we have to *schedule* the dispatch instead of simply
   calling `$dispatcher->call()` directly? This question cuts to the heart of event-driven
   programming and the simple answer is that *"because we have to."* The parallel behavior of the
   job dispatcher relies on the underlying non-blocking functionality of the `Alert\Reactor` object
   we passed it back in **step #3**. As a result, your asynchronous calls won't be dispatched until
   we actually *start the event loop.* So you can probably guess what happense next ...
7. Finally, we start the event loop.


#### What's *really going on* in that code

The output of the above demo script is itself rather unremarkable. Basically all we did was call
`sleep(1)` three times but with a lot more overhead than would have been required had we simply
invoked it three times directly. **But wait ...** if you actually run the example code in the
console you'll see that although we made three `sleep(1)` calls the program still completed in ~1
second.

This is the magic of parallel processing. Had we synchronously called `sleep(1)` three times in our
program it would have needed ~3 seconds to complete. Of course, telling our CPU to take a nap three
times is a spurious use of parallelization to be sure; but the larger implication should be clear:

> We can use parallel asynchronous calls to execute multiple IO-bound operations (like database
> queries) at the same time and significantly improve the speed with which we're able to generate
> the overall result.

Additionally, because the calls to `Amp\JobDispatcher::call()` return immediately we can continue
doing other things while we wait for our result callbacks to return.


## 5. The tip of the iceberg

Hopefully this brief guide is enough to get you started with AMP. It's worth noting that there is an
entire untapped world of event-driven programming that offers significant benefits over traditional
synchronous PHP code. AMP can help you tap into this paradigm for distributed processing via the job
server but recognize that you can also use the library to bypass remote job servers altogether and
use the same `Amp\Dispatcher` API to parallelize calls to worker processes in CLI applications and
daemon scripts. Meanwhile, you can use the coupled [`Alert`][alert-repo] library to schedule, pause,
enable and cancel recurring or one-time events as well as watch sockets and streams to create
non-blocking servers and applications.

Please take some time to peruse the files in the AMP `examples/` directory as well as the
[`Alert`][alert-repo] github page to learn more about the possibilities.

[alert-repo]: https://github.com/rdlowrey/Alert "Alert"
