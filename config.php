<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2016 - 2018 Kopano b.v.
 * SPDX-FileCopyrightText: Copyright 2020 - 2026 grommunio GmbH
 *
 * Configuration file for GrommunioDAV.
 */

define('MAPI_SERVER', 'default:');

// Authentication realm
define('SABRE_AUTH_REALM', 'grommunio dav');

// Location of the SabreDAV server.
define('DAV_ROOT_URI', '/dav/');

// Database of the GAL cache (PDO syntax). The sync state is kept in the
// hidden folder "GD-SyncState" in the store of each user.
define('SYNC_DB', 'sqlite:/var/lib/grommunio-dav/syncstate.db');

// Number of items to send in one request.
define('MAX_SYNC_ITEMS', 1000);

// Developer mode: verifies log messages
define('DEVELOPER_MODE', true);

// Global Address List (GAL) as read-only CardDAV address book
define('GAL_ENABLED', false);
define('GAL_CACHE_TTL', 3600); // seconds

/*
 * Allow users with necessary permissions to open a shared folder.
 * The login name is composed of the user that is authenticating separated with the user to be
 * impersonated without the domain name with a single '!', like
 *   impersonated-user!auth-user@domain.com
 *
 * When the impersonated and auth user have different domains you need to supply the
 * domain too and replace the '@' from the impersonated mailbox with a '!' aswell, like
 *   impersonated-user!test.com!auth-user@domain.com
 *
 * The password is of course of the authenticating user.
 */
define('ALLOW_IMPERSONATE', false);

// Logging: adjust in glogger.ini
