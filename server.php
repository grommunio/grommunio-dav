<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2016 - 2018 Kopano b.v.
 * SPDX-FileCopyrightText: Copyright 2020-2024 grommunio GmbH
 *
 * This is the entry point through which all requests are processed.
 */

namespace grommunio\DAV;

use Sabre\CalDAV\CalendarRoot;
use Sabre\CalDAV\ICSExportPlugin;
use Sabre\CardDAV\AddressBookRoot;
use Sabre\CardDAV\Plugin;
use Sabre\DAV\Server;
use Sabre\DAV\Version;
use Sabre\DAVACL\PrincipalCollection;

// require composer auto-loader
require __DIR__ . '/vendor/autoload.php';

// Configure & create main logger
GLogger::configure(__DIR__ . '/glogger.ini');
$logger = new GLogger('main');

// don't log any Sabre asset requests (images etc)
if (isset($_REQUEST['sabreAction']) && $_REQUEST['sabreAction'] == 'asset') {
	$logger->resetConfiguration();
}

// log the start data
$logger->debug('------------------ Start');
$logger->debug('%s %s', $_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
$logger->debug('grommunio-dav version %s', GDAV_VERSION);
$logger->debug('SabreDAV version %s', Version::VERSION);

$gdavBackend = new GrommunioDavBackend(new GLogger('dav'));
if (defined("SABRE_AUTH_BACKEND") && strcmp(SABRE_AUTH_BACKEND, "apache") == 0) {
	$authBackend = new AuthApache;
} else {
	$authBackend = new AuthBasicBackend($gdavBackend);
}
$authBackend->setRealm(SABRE_AUTH_REALM);
$principalBackend = new PrincipalsBackend($gdavBackend);
$gCarddavBackend = new GrommunioCardDavBackend($gdavBackend, new GLogger('card'));
$gCaldavBackend = new GrommunioCalDavBackend($gdavBackend, new GLogger('cal'));

// Setting up the directory tree
$nodes = [
	new PrincipalCollection($principalBackend),
	new AddressBookRoot($principalBackend, $gCarddavBackend),
	new CalendarRoot($principalBackend, $gCaldavBackend),
];

// initialize the server
$server = new Server($nodes);
$server->setBaseUri(DAV_ROOT_URI);
$server->setLogger($logger->getGPSR3Logger());

$authPlugin = new \Sabre\DAV\Auth\Plugin($authBackend);
$server->addPlugin($authPlugin);

// add our version to the headers
$server->httpResponse->addHeader('X-GDAV-Version', GDAV_VERSION);

// log the incoming request (only if authenticated)
$logger->LogIncoming($server->httpRequest);

$aclPlugin = new DAVACL();
$aclPlugin->allowUnauthenticatedAccess = false;
$server->addPlugin($aclPlugin);

$schedulePlugin = new GrommunioSchedulePlugin($gdavBackend, new GLogger('schedule'));
$server->addPlugin($schedulePlugin);

$imipPlugin = new GrommunioIMipPlugin($gdavBackend, new GLogger('imip'));
$server->addPlugin($imipPlugin);

$server->addPlugin(new ICSExportPlugin());
$server->addPlugin(new Plugin());

// TODO: do we need $caldavPlugin for anything?
$caldavPlugin = new \Sabre\CalDAV\Plugin();
$server->addPlugin($caldavPlugin);

if (strlen(SYNC_DB) > 0) {
	$server->addPlugin(new \Sabre\DAV\Sync\Plugin());
}

if (DEVELOPER_MODE) {
	$server->addPlugin(new \Sabre\DAV\Browser\Plugin(false));
}

$server->start();

// Log outgoing data
$logger->LogOutgoing($server->httpResponse);

$logger->debug(
	"httpcode='%s' memory='%s/%s' time='%ss'",
	http_response_code(),
	$logger->FormatBytes(memory_get_peak_usage(false)),
	$logger->FormatBytes(memory_get_peak_usage(true)),
	number_format(microtime(true) - $_SERVER["REQUEST_TIME_FLOAT"], 2)
);
$logger->debug('------------------ End');
