> **DISCLAIMER:** AMP isn't finished _yet_. At this time only the worker process pool dispatcher
> is complete. The multithreading dispatcher is ~75% complete and work has not yet begun on the 
> Gearman adapter. The dispatcher interface and event reactors have stabilized, though, and are
> safe for use.

... intermezzo ...

## AMP: Asynchronous Multiprocessing for PHP

AMP is an OS-agnostic framework providing event-driven concurrency and non-blocking IO for PHP 
applications and socket servers. Current evented PHP systems err by requiring non-blocking semantics
for computational concurrency. **This is a mistake**. The solution to the problem of event-driven
concurrency is *NOT* to write and require non-blocking versions of every PHP function, but instead 
to create tools allowing evented code to utilize the vast synchronous toolkit already available to
PHP developers.

AMP solves this problem by way of its `Dispatcher` API which allows parallelization of IO operations
in both Windows *and* POSIX-style environments without the need for additional PHP extensions.
Dispatchers route RPC-style asynchronous calls to worker process pools, worker threads or Gearman
job servers. Meanwhile, mutable event subscriptions allow pausing/playing events while switching 
back and forth between evented and synchronous code as needed.

> **HEY!** Checkout out the [**EXAMPLES**](https://github.com/rdlowrey/Amp/tree/master/examples)
> to see some of the cool stuff AMP can do.

##### FEATURES

 - Offers a single unified API for interfacing with worker processes, worker threads and Gearman
   job servers.
 - Integrates both native and libevent reactors for out-of-the-box compatibility with any PHP
   install while offering high-end performance when ext/libevent is present.
 - Provides pause/play functionality for individual event callbacks, recurring timed events,
   event-loop ticking and the ability to start/stop the reactor at any time without losing state.
 - Supplies three different paradigms for handling event-driven design: Subject/Observer attachment,
   callbacks and Promises.
 - Allows RPC-style calls to worker processes in languages that *aren't* PHP via a custom
   library-specific inter-process messaging protocol. Python is the only language with a packaged
   AMP adapter at this time.

##### PROJECT GOALS

* Provide accessible parallelization and concurrency without the need for non-blocking semantics;
* Establish a unified API for multi-processing, multi-threading and remote job dispatch;
* Build all components using [SOLID][solid], readable and thoroughly tested code.

##### INSTALLATION

```bash
$ git clone https://github.com/rdlowrey/Amp.git
```

##### REQUIREMENTS

* PHP 5.4+

###### Optional:

* `ext/libevent` Required to use the `LibeventReactor`; provides ~3x faster event-loop execution.


[solid]: http://en.wikipedia.org/wiki/SOLID_(object-oriented_design) "S.O.L.I.D."
