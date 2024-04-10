<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2016 - 2018 Kopano b.v.
 * SPDX-FileCopyrightText: Copyright 2020 grommunio GmbH
 *
 * grommunio DAV ACL class.
 */

namespace grommunio\DAV;

use Sabre\DAVACL\Plugin;

class DAVACL extends Plugin {
	/**
	 * Returns the full ACL list.
	 *
	 * Either a uri or a DAV\INode may be passed.
	 *
	 * null will be returned if the node doesn't support ACLs.
	 *
	 * @param DAV\INode|string $node
	 *
	 * @return array
	 */
	public function getACL($node) {
		return [
			[
				'privilege' => '{DAV:}all',
				'principal' => '{DAV:}authenticated',
				'protected' => true,
			],
		];
	}
}
