## AMP: Asynchronous Multiprocessing in PHP

AMP parallelizes asynchronous function calls to worker thread pools. Its goal is to simplify the 
use of [`ext/pthreads`][pthreads] in non-blocking applications. AMP allows developers to write
synchronous functions which are dispatched to the thread pool and returned asynchronously. This
simple approach makes concurrency and distributed processing a reality for PHP coders of any skill
level. AMP's OS-agnostic functionality is available in both Windows and POSIX environments via
the [pthreads extension][pthreads].

## Project Goals

* Simplify threaded parallelization in non-blocking PHP code;
* Build all components using [SOLID][solid], readable and unit-tested code.

## Requirements

* [PHP 5.4+][php-net] ... duh
* [`ext/pthreads`][pthreads] The pthreads extension ([windows .DLLs here][win-pthreads-dlls])
* [rdlowrey/alert][alert] Alert IO/events (retrieved automatically with `$ git clone --recursive`)

[php-net]: http://php.net "php.net"
[pthreads]: http://pecl.php.net/package/pthreads "pthreads"
[solid]: http://en.wikipedia.org/wiki/SOLID_(object-oriented_design) "S.O.L.I.D."
[alert]: https://github.com/rdlowrey/Alert "Alert IO/events"
[win-pthreads-dlls]: http://windows.php.net/downloads/pecl/releases/pthreads/ "pthreads Windows DLLs"

## Installation

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