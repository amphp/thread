AMP: Async Multiprocessing in PHP
---------------------------------

Amp parallelizes asynchronous function calls to worker thread pools. Its goal is to simplify the
use of blocking functions and libraries in non-blocking applications. The library allows developers
to write synchronous code which is then dispatched to a thread pool and returned asynchronously.
This functionality is exposed in an OS-agnostic manner via the [pthreads extension][pthreads].

**Problem Domain**

PHP has a broad catalog of synchronous libraries and extensions. However, it's often difficult to
find non-blocking libs to use inside our event loop. Beyond this limitation, there are common tasks
(like filesystem IO) which don't play nice with the non-blocking paradigm. Unfortunately, threading
is an altogether different approach to concurrency from that used in non-blocking applications.
Amp's goal is to seemlessly expose access to threaded concurrency inside event-loop applications.


### Project Goals

* Expose threaded parallelization in non-blocking PHP code;
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

###### Git:

```bash
$ git clone --recursive git@github.com:rdlowrey/Amp.git
```

###### Composer:

```bash
$ php composer.phar require rdlowrey/amp:0.4.*
```

## Examples

##### Dispatching from the Event Loop
@TODO

##### pthreads Pitfalls
@TODO

##### Cancelling Dispatched Calls
@TODO

##### Call Timeouts
@TODO