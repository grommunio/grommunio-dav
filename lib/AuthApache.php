<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: 2025 grommunio GmbH
 *
 * Use this class when the HTTP server is in charge of authentication.
 */

namespace grommunio\DAV;

use Sabre\DAV\Auth\Backend\Apache;
use Sabre\DAV\Exception\NotImplemented;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;

class AuthApache extends Apache {
	protected $realm = 'SabreDAV';

	/**
	 * Constructor.
	 */
	public function __construct(protected GLogger $logger) {}

	/**
	 * Sets the authentication realm.
	 *
	 * @param string $realm
	 */
	public function setRealm($realm) {
		$this->realm = $realm;
	}

	/**
	 * Checks the user authenticated by the HTTP server.
	 *
	 * The user is authenticated, but the DAV backend needs a MAPI session
	 * for the user. php-mapi only allows a logon without password for
	 * command line scripts, not for requests of a web server, so there is
	 * no way to get one.
	 *
	 * @return array
	 *
	 * @throws NotImplemented if the HTTP server authenticated a user
	 */
	public function check(RequestInterface $request, ResponseInterface $response) {
		$result = parent::check($request, $response);
		if (!$result[0]) {
			return $result;
		}
		$this->logger->error("Authentication by the HTTP server (SABRE_AUTH_BACKEND \"apache\") is not supported: a MAPI session cannot be opened without the password of %s, use the basic authentication backend", $result[1]);

		throw new NotImplemented('Authentication by the HTTP server is not supported');
	}

	/**
	 * Asks the client for credentials.
	 */
	public function challenge(RequestInterface $request, ResponseInterface $response) {
		$response->addHeader('WWW-Authenticate', 'Basic realm="' . addcslashes($this->realm, '"\\') . '", charset="UTF-8"');
	}
}
