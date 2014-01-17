v0.4.0-dev
----------

- Major migration to pthreads-only functionality. Previous versions no longer supported.

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
