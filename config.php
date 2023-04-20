<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2016 - 2018 Kopano b.v.
 * SPDX-FileCopyrightText: Copyright 2020 grommunio GmbH
 *
 * Configuration file for GrommunioDAV.
 */

define('MAPI_SERVER', 'default:');

// Authentication realm
define('SABRE_AUTH_REALM', 'grommunio dav');

// Location of the SabreDAV server.
define('DAV_ROOT_URI', '/dav/');

// Location of the sync database (PDO syntax)
define('SYNC_DB', 'sqlite:/var/lib/grommunio-dav/syncstate.db');

// Number of items to send in one request.
define('MAX_SYNC_ITEMS', 1000);

// Developer mode: verifies log messages
define('DEVELOPER_MODE', true);

// Logging: adjust in glogger.ini
