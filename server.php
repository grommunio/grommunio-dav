<?
/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2016 - 2018 Kopano b.v.
 * SPDX-FileCopyrightText: Copyright 2020 grammm GmbH
 *
 * This is the entry point through which all requests are processed.
 */

namespace grammm\DAV;

// require composer auto-loader
require __DIR__ . '/vendor/autoload.php';

// FIXME:  solve the including of shared files properly
require_once GROMOX_PHP_PATH . 'lib/db.php';

// Configure & create main logger
GLogger::configure(__DIR__ . '/log4php.xml');
$logger = new GLogger('main');

// don't log any Sabre asset requests (images etc)
if (isset($_REQUEST['sabreAction']) && $_REQUEST['sabreAction'] == 'asset') {
    $logger->resetConfiguration();
}

// log the start data
$logger->debug('------------------ Start');
$logger->debug('%s %s', $_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
$logger->debug('grammm-dav version %s', GDAV_VERSION);
$logger->debug('SabreDAV version %s',\Sabre\DAV\Version::VERSION);

$gdavBackend = new GrammmDavBackend(new GLogger(('dav')));
$authBackend = new AuthBasicBackend($gdavBackend);
$authBackend->setRealm(SABRE_AUTH_REALM);
$principalBackend = new PrincipalsBackend($gdavBackend);
$gCarddavBackend  = new GrammmCardDavBackend($gdavBackend, new GLogger('card'));
$gCaldavBackend   = new GrammmCalDavBackend($gdavBackend, new GLogger('cal'));

// Setting up the directory tree
$nodes = array(
    new \Sabre\DAVACL\PrincipalCollection($principalBackend),
    new \Sabre\CardDAV\AddressBookRoot($principalBackend, $gCarddavBackend),
    new \Sabre\CalDAV\CalendarRoot($principalBackend, $gCaldavBackend),
);

// initialize the server
$server = new \Sabre\DAV\Server($nodes);
$server->setBaseUri(DAV_ROOT_URI);
$server->setLogger(new GPSR3Logger($logger));

$authPlugin = new \Sabre\DAV\Auth\Plugin($authBackend, SABRE_AUTH_REALM);
$server->addPlugin($authPlugin);

// add our version to the headers
$server->httpResponse->addHeader('X-GDAV-Version', GDAV_VERSION);

// log the incoming request (only if authenticated)
$logger->LogIncoming($server->httpRequest);

$aclPlugin = new DAVACL();
$aclPlugin->allowUnauthenticatedAccess = false;
$server->addPlugin($aclPlugin);

$schedulePlugin = new GrammmSchedulePlugin($gdavBackend, new GLogger('schedule'));
$server->addPlugin($schedulePlugin);

$imipPlugin = new GrammmIMipPlugin($gdavBackend, new GLogger('imip'));
$server->addPlugin($imipPlugin);

$server->addPlugin(new \Sabre\CalDAV\ICSExportPlugin());
$server->addPlugin(new \Sabre\CardDAV\Plugin());

// TODO: do we need $caldavPlugin for anything?
$caldavPlugin = new \Sabre\CalDAV\Plugin();
$server->addPlugin($caldavPlugin);

if (strlen(SYNC_DB) > 0) {
    $server->addPlugin(new \Sabre\DAV\Sync\Plugin());
}

if (DEVELOPER_MODE) {
    $server->addPlugin(new \Sabre\DAV\Browser\Plugin(false));
}

$server->exec();

// Log outgoing data
$logger->LogOutgoing($server->httpResponse);

$logger->debug("httpcode='%s' memory='%s/%s' time='%ss'",
                http_response_code(), $logger->FormatBytes(memory_get_peak_usage(false)), $logger->FormatBytes(memory_get_peak_usage(true)),
                number_format(microtime(true) - $_SERVER["REQUEST_TIME_FLOAT"], 2));
$logger->debug('------------------ End');
