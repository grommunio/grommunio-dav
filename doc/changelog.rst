grommunio-dav 1.5 (2025-04-06)
==============================

Fixes:

* Urlencode vcaluids in anticipation of special characters

Behavioral changes:

* nginx: extend default fastcgi_read_timeout to 360s
* nginx: do not intercept DAV errors transported back to clients
* curb excessive calendar logging


grommunio-dav 1.4 (2025-01-28)
==============================

Fixes:

* Set default properties like last modification time for cross-device compatibility

Enhancements:

* Allow custom configuration snippets for nginx config templates
* Improvements on PHP 8.2+ support
* Optimizations on CardDAV setData calls
* Switch to Monolog facility

Behavioral changes:

* Respect the USER_PRIVILEGE_DAV flag of the user account on login
