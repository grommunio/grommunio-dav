grommunio DAV
=============

grommunio DAV is an open-source application to provide CalDAV and CardDAV to
compatible applications and devices such as macOS Calendar, macOS Contacts,
Thunderbird/Lightning and others.

|shield-agpl|_ |shield-release|_ |shield-scrut|_ |shield-loc|

.. |shield-agpl| image:: https://img.shields.io/badge/license-AGPL--3.0-green
.. _shield-agpl: LICENSE
.. |shield-release| image:: https://shields.io/github/v/tag/grommunio/grommunio-dav
.. _shield-release: https://github.com/grommunio/grommunio-dav/tags
.. |shield-scrut| image:: https://img.shields.io/scrutinizer/build/g/grommunio/grommunio-dav
.. _shield-scrut: https://scrutinizer-ci.com/g/grommunio/grommunio-dav
.. |shield-loc| image:: https://img.shields.io/github/languages/code-size/grommunio/grommunio-dav

At a glance
===========

* Provides standardized CalDAV and CardDAV interfaces to groupware data
  (contacts, calendar and tasks).
* Multi-platform supports various CalDAV and CardDAV clients, such as macOS
  Calendar, macOS Contacts, Thunderbird/Lightning, Evolution and many other
  CalDAV/CardDAV clients as well as other applications used, such as
  `Dash <https://get-dash.com>`_.
* Compatible, works with various web servers such as nginx, apache and others;
  usage of nginx is recommended.
* Highly efficient, averaging at 4MB per connection, per device of memory usage
  (using nginx with php-fpm).
* Distributable, compatible with load balancers such as haproxy, apisix, KEMP
  and others.
* Scalable, enabling multi-server and multi-location deployments.
* High-performance, allowing nearly wire speeds for store synchronization.
* Secure, with certifications through independent security research and
  validation.

Built with
==========

* PHP 7.4+, 8.x
* PHP modules: ctype, curl, dom, iconv, mbsting, sqlite, xml, xmlreader, xmlwriter
* PHP backend module: mapi

Getting started
===============

Prerequisites
-------------

* A working web server (nginx is recommended), with a working TLS configuration
* PHP, preferably available as fpm pool
* Zcore MAPI transport (provided by `Gromox <https://github.com/grommunio/gromox>`_)

Installation
------------

* Deploy grommunio-dav at a location of your choice, such as
  ``/usr/share/grommunio-dav``.
* Adapt ``version.php`` with the adequate version string, see
  `</build/version.php.in>`_.
* Provide a default configuration file as config.php, see `</config.php>`_.
* Adapt web server configuration according to your needs, [/build](/build)
  provides some examples.
* Prepare PHP configuration according to your needs, [/build](/build) provides
  some examples.
* (Optional) Setup of DNS SRV records for simplified account configuration is
  recommended:

  .. code-block:: text

	_carddavs._tcp 86400 IN SRV 10 20 443 my.example.com.
	_caldavs._tcp  86400 IN SRV 10 20 443 my.example.com.
	_caldavs._tcp  86400 IN TXT path=/dav
	_carddavs._tcp 86400 IN TXT path=/dav

Logging
-------

grommunio DAV uses `log4php <http://logging.apache.org/log4php/>`_ for logging.
Adjust `</log4php.xml>`_ to match your needs.

Usage
-----

* You can use your webbrowser to point to ``https://my.example.com/dav/``, or
  alternatively directly to your calendar URL
  ``https://my.example.com/dav/calendars/<user>/Calendar/``
* Enter your account credentials (username and password)

Support
=======

Support is available through grommunio GmbH and its partners. See
https://grommunio.com/ for details. A community forum is at
`<https://community.grommunio.com/>`_.

For direct contact and supplying information about a security-related
responsible disclosure, contact `dev@grommunio.com <dev@grommunio.com>`_.

Contributing
============

* https://docs.github.com/en/get-started/quickstart/contributing-to-projects
* Alternatively, upload commits to a git store of your choosing, or export the
  series as a patchset using `git format-patch
  <https://git-scm.com/docs/git-format-patch>`_, then convey the git
  link/patches through our direct contact address (above).

Coding style
------------

This repository follows a custom coding style, which can be validated anytime
using the repository's provided `configuration file <.phpcs>`_.
