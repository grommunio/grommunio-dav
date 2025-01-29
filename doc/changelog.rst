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
