## AMP: Asynchronous Multiprocessing for PHP

**AMP** is an OS-agnostic framework providing event-driven concurrency and non-blocking IO for PHP 
applications and socket servers. Current evented PHP systems err by requiring non-blocking semantics
for computational concurrency. **This is a mistake**. The solution to the problem of event-driven
concurrency is *NOT* to write and require non-blocking versions of *every imaginable PHP function*,
but instead to create tools allowing evented code to utilize the vast toolkit of synchronous 
functionality already available to PHP developers.

AMP solves this problem by way of its `Dispatcher` API which allows parallelization of IO operations
in both Windows *and* POSIX-style environments without the need for additional PHP extensions.
Dispatchers route RPC-style asynchronous calls to worker process pools, worker threads or Gearman
job servers. Meanwhile, mutable event subscriptions allow pausable/resumable events and switching 
back and forth between evented and synchronous code as needed.

> **HEY!** Checkout out the [**EXAMPLES**](https://github.com/rdlowrey/Amp/tree/master/examples)
> to see examples of the cool things AMP can do.

##### FEATURES

 - Offers a simple API for parallelizing function calls to a pool of worker processes;
 - Allows RPC-style calls to worker processes in languages that *aren't* PHP via a custom
   inter-process messaging protocol;
 - Integrates both native *AND* libevent reactors for out-of-the-box compatibility with any PHP
   install on running on any operating system;
 - Provides pause/play functionality for individual event callbacks, recurring timed events,
   event-loop ticking and the ability to start/stop the reactor at any time without losing state.

###### IN DEVELOPMENT:

 - `Dispatcher` implementation for asynchronously parallelizing tasks to worker threads via PHP's
   `ext/pthreads` extension.

###### PLANNED:

 - `Dispatcher` implementation for asynchronously parallelizing tasks to Gearman servers using PHP's
   `ext/gearman` extension.

##### PROJECT GOALS

* Provide accessible parallelization and concurrency without non-blocking semantics;
* Utilize a performant event loop abstractiong for non-blocking socket IO and timer events;
* Establish a unified API for multi-processing, multi-threading and remote job dispatch;
* Build all components using [SOLID][solid], readable and thoroughly tested code.

##### INSTALLATION

```bash
$ git clone https://github.com/rdlowrey/Amp.git
```

##### REQUIREMENTS

* PHP 5.4+

###### OPTIONAL:

* `ext/libevent` Required to use the `LibeventReactor`; results in ~3x faster event-loop execution.


[solid]: http://en.wikipedia.org/wiki/SOLID_(object-oriented_design) "S.O.L.I.D."
