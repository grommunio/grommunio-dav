grommunio-dav 1.8.x (dev)
=========================

Fixes:

* Hide private elements and tasks for others except delegates. Non-delegates
  only see schedule info (no details)
* Evaluate PidLidPrivate for private appointments (not only PR_SENSITIVITY)

Enhancements:

* Calendars without write permissions are now properly offered read-only to
  clients
* Calendars without at least read permissions are not listed / made visible
* Respond appropriately to MAPI errors (permissions, journal, etc.) on
  PUT/DELETE with 201/204 for invalid access and 415 for conversion errors
  which allows clients to deal with issues instead of silently "ignoring"
* Support for distribution lists (contact groups)
* Stable UID for contacts without vCard UID

grommunio-dav 1.8 (2026-09-24)
==============================

Fixes:

* Hierarchy of another user without foldervisible permission on the top of
  the information store returned no folders
* Warning for stores without PR_EC_ENABLED_FEATURES_L

Enhancements:

* User impersonation (ALLOW_IMPERSONATE)
* Calendar restriction built by getCalendarRestriction() from mapi-header-php,
  which is now required in version 2.3
* Modern constructor and member initialization

grommunio-dav 1.7 (2026-07-27)
==============================

Fixes:

* Stalled sync of some Apple devices
* Calendar metadata (color/order/displayname) from MKCALENDAR not applied
* Empty goid values resulted in wrong objects on lookup

Enhancements:

* Use primary_email from nsp_getuserinfo in principals
* Add logger to the PrincipalsBackend
* Read-only GAL
* Add named property constants for DAV folder metadata
* Add folder-level property read/write helpers
* Return composite id from CreateFolder
* Expose schedule-calendar-transp per calendar folder
* Addressbook-description on folder PROPFIND
* Implement updateCalendar to persist PROPPATCH mutations
* Rename address books and edit their description
* Improve handling for mismatching UID and objectUri
* Support open-ended time-range in calendar-query
* Improve goid resolving

grommunio-dav 1.6 (2025-12-16)
==============================

Fixes:

* Login with altnames
* New appointment as unread in grommunio-web and outlook

Enhancements:

* Task support
* Sabre's Apache authentication backend
* Let client know of server-side sync-token changes
* Log IP address for failed logins

Behavioral changes:

* move phpcs config file to recommended default


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
