<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: 2025 grommunio GmbH
 *
 * Use this class when the HTTP server is in charge of authentication.
 */

namespace grommunio\DAV;

use Sabre\DAV\Auth\Backend\Apache;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;

class AuthApache extends Apache {
	protected $stored_realm;
	public function setRealm($r)
	{
		$stored_realm = $r;
	}
	public function challenge(RequestInterface $rq, ResponseInterface $rs)
	{
		$rs->addHeader("WWW-Authenticate", "Basic realm=$stored_realm");
	}
}
