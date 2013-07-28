## AMP: Asynchronous Multiprocessing for PHP

AMP parallelizes asynchronous RPC-style function calls to a pool of worker processes inside 
non-blocking event loops. The OS-agnostic functionality is available in both Windows *and* POSIX
environments and requires no PHP extensions.

Current evented PHP libraries require non-blocking semantics for computational concurrency. *This is
a serious mistake*. The solution to the problem of event-driven concurrency is *NOT* to write and
require non-blocking versions of *every imaginable PHP function*. Instead, we should allow evented
code to utilize the vast synchronous toolkit already available to PHP developers.

> **HEY!** Checkout out the [**EXAMPLES**](https://github.com/rdlowrey/Amp/tree/master/examples)
> to see some of the cool things AMP can do.

##### FEATURES

 - Offers a simple API for parallelizing function calls to a pool of worker processes;
 - Allows RPC-style calls to worker processes in languages that *aren't* PHP via a custom
   inter-process messaging protocol;
 - Integrates both native *AND* libevent event loop reactors for out-of-the-box compatibility with
   any PHP install on any operating system;

###### IN DEVELOPMENT:

 - A lightweight threaded `Dispatcher` implementation to parallelize tasks to worker threads using
   PHP's [*pthreads*][pthreads] extension.
 - Socket server implementation for distributing work across different machines or requests in web
   SAPI environments.
 - An event reactor utilizing `Libev` via PHP's [*ev*][ev] extension.

##### PROJECT GOALS

* Provide accessible parallelization and concurrency without non-blocking semantics;
* Utilize a performant event loop abstractiong for non-blocking socket IO and timer events;
* Establish a unified API for multi-processing, multi-threading and remote job dispatch;
* Build all components using [SOLID][solid], readable and thoroughly tested code.

##### INSTALLATION

###### Git:

```bash
$ git clone https://github.com/rdlowrey/Amp.git
```

###### Composer:

```bash
$ php composer.phar require rdlowrey/amp:0.1.*
```

##### REQUIREMENTS

* PHP 5.4+

###### OPTIONAL:

* [*libevent*][libevent] Required to use the `LibeventReactor` for ~3x faster event-loop execution.


[pthreads]: http://pecl.php.net/package/pthreads "pthreads"
[ev]: http://pecl.php.net/package/ev "ev"
[solid]: http://en.wikipedia.org/wiki/SOLID_(object-oriented_design) "S.O.L.I.D."
[libevent]: http://pecl.php.net/package/libevent "libevent"
