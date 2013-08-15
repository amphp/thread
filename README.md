## AMP: Asynchronous Multiprocessing in PHP

> [**Read the QUICKSTART Guide**][quickstart]

AMP parallelizes asynchronous RPC-style function calls to job servers and worker process pools. Its
goal is to trivialize distributed PHP processing and failover by eliminating the difficulties of
threading and non-blocking semantics. AMP's OS-agnostic functionality is available in both Windows
*and* POSIX environments and requires no additional PHP extensions.

###### Why?

Existing evented PHP libraries require non-blocking semantics for computational concurrency. However,
this is a suboptimal solution to the problem of event-driven concurrency because it enforces the use
of non-blocking APIs to accomplish anything. Instead, evented code should have access to the vast
synchronous toolkit already available to PHP developers. AMP allows developers to write synchronous
functions which are then *executed and returned asynchronously.* This simple approach makes
concurrency and distributed processing a reality for PHP coders of any skill level.


#### FEATURES

 - Offers a simple API for parallelizing function calls to job servers and worker processes;
 - Supplies a TCP job server for distributed processing in both CLI and web SAPI applications;
 - Allows RPC-style calls to worker processes in languages that *aren't* PHP via a custom
   inter-process messaging protocol;


#### IN DEVELOPMENT

 - A lightweight threaded `Dispatcher` implementation to parallelize tasks to worker threads using
   PHP's [*pthreads*][pthreads] extension (as opposed to more heavy-weight worker processes);


#### PROJECT GOALS

* Simplify PHP parallelization and concurrency by minimizing threaded/non-blocking semantics;
* Establish a unified API for multi-processing, multi-threading and distributed processing/failover;
* Build all components using [SOLID][solid], readable and thoroughly-tested code.


#### INSTALLATION

###### Git:

```bash
$ git clone https://github.com/rdlowrey/Amp.git
$ composer install
```

###### Composer:

```bash
$ composer require rdlowrey/amp:0.2.*
$ composer install
```


#### REQUIREMENTS

* PHP 5.4+
* [rdlowrey/alert][alert] event reactor library (retrieved automatically with `$ git clone --recursive`)
* *Optional:* [libevent][libevent] for faster evented execution and high-volume socket connections


[quickstart]: https://github.com/rdlowrey/Amp/blob/master/QUICKSTART.md "AMP QUICKSTART"
[pthreads]: http://pecl.php.net/package/pthreads "pthreads"
[ev]: http://pecl.php.net/package/ev "ev"
[solid]: http://en.wikipedia.org/wiki/SOLID_(object-oriented_design) "S.O.L.I.D."
[alert]: https://github.com/rdlowrey/Alert "Alert event reactor"
[libevent]: http://pecl.php.net/package/libevent "libevent"
