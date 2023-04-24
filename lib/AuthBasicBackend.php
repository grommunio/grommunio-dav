<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2016 - 2018 Kopano b.v.
 * SPDX-FileCopyrightText: Copyright 2020 grommunio GmbH
 *
 * grommunio basic authentication backend class.
 */

namespace grommunio\DAV;

class AuthBasicBackend extends \Sabre\DAV\Auth\Backend\AbstractBasic {
	protected $gDavBackend;

	/**
	 * Constructor.
	 */
	public function __construct(GrommunioDavBackend $gDavBackend) {
		$this->gDavBackend = $gDavBackend;
	}

	/**
	 * Validates a username and password.
	 *
	 * This method should return true or false depending on if login
	 * succeeded.
	 *
	 * @see \Sabre\DAV\Auth\Backend\AbstractBasic::validateUserPass()
	 *
	 * @param string $username
	 * @param string $password
	 *
	 * @return bool
	 */
	protected function validateUserPass($username, $password) {
		return $this->gDavBackend->Logon($username, $password);
	}
}
