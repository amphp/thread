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
