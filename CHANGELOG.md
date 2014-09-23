v0.8.0
------

- Migrate repo to new amphp/thread repo

> **BC Breaks:**

- The library now resides at `amphp/thread` (was `rdlowrey/Amp`)
- All existing references to `Alert` or `After` libs must be refactored to point to the same
  classes in the new `Amp` repo.

v0.7.0
------

- Use completely refactored (new) After dependency
- Allow for custom task progress updates via `After\Promise` API
- Cleanup edge-case IPC failure in worker threads
- Add new "After" submodule for moved concurrency primitives

> **BC Breaks:**

- This update uses the new 0.2.x version of the After dependency. As such, the public API dealing
  with promised results has completely changed. Please see https://github.com/rdlowrey/After for
  details.

v0.6.0
------

- Removed `Dispatcher::OPT_ON_WORKER_TASK` option
- Worker start tasks are now controlled with the following new methods:
    - `Dispatcher::addWorkerStartTask(Threaded $task)`
    - `Dispatcher::removeWorkerStartTask(Threaded $task)`

v0.5.0
------

- Pool size is now elastic subject to min/max size configuration settings
- Worker threads exceeding the idle timeout (since last processing activity) are
  now automatically unloaded to scale thread pool size back when not under load.
- New option constants:
    - `Dispatcher::OPT_POOL_SIZE_MIN`
    - `Dispatcher::OPT_POOL_SIZE_MAX`
    - `Dispatcher::OPT_IDLE_WORKER_TIMEOUT` (seconds)
- Updated rdlowrey\Alert dependency to latest
- Default worker thread task execution limit before recycling is now 2048 (was 1024)
- Performance improvements when tasks are rejected due to excessive load

v0.4.0
------

- Major migration to pthreads-only functionality. Previous versions no longer supported.
- Job server support removed.

#### v0.3.1

- Addressed fatal error when returning data frames from worker processes exceeding 65535 bytes in size.

v0.3.0
------

- Job server script renamed (bye-bye .php extension), added hashbang for easier execution in
  _*nix_ environments
- Job server binary now correctly interprets space separators in command line arguments
- Composer support improved, now plays nice with submodules
- Convenience autoloader script moved into vendor directory to play nice with composer


#### v0.2.2

- Minor bugfixes

#### v0.2.1

- Addressed execution time drift in repeating native reactor alarms
- Addressed infinite recursion in repeating callbacks

v0.2.0
------

> **NOTE:** This release introduces significant changes in the AMP API to affect performance and
> functionality improvements; BC breaks are prevalent and blindly upgrading will break your
> application. v0.1.0 is deprecated and no longer supported.

- Extracted event reactor functionality into separate [Alert][alert-repo] repo
- Added TCP job server (`bin/amp.php`) and asynchronous clients for interfacing with job servers
- Messaging transport protocols extended and simplified to favor bytes over bits in places
- Removed IO stream watcher timeouts
- Removed schedule watcher iteration limits
- Removed subscription/watcher object abstraction in favor of watcher IDs
- Reactors now control all `enable/disable/cancel` actions for timer/stream watchers

v0.1.0
------

- Initial tagged release

[alert-repo]: https://github.com/rdlowrey/Alert "Alert"
