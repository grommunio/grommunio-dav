<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2016 - 2018 Kopano b.v.
 * SPDX-FileCopyrightText: Copyright 2020 - 2024 grommunio GmbH
 *
 * grommunio DAV ACL class.
 */

namespace grommunio\DAV;

use Sabre\CalDAV\Calendar;
use Sabre\CalDAV\CalendarObject;
use Sabre\DAV\INode;
use Sabre\DAVACL\Plugin;

class DAVACL extends Plugin {
	/**
	 * Returns the full ACL list.
	 *
	 * Either a uri or a DAV\INode may be passed.
	 *
	 * null will be returned if the node doesn't support ACLs.
	 *
	 * @param INode|string $node
	 *
	 * @return array
	 */
	public function getACL($node) {
		if (is_string($node)) {
			$node = $this->server->tree->getNodeForPath($node);
		}
		if ($node instanceof GalAddressBook || $node instanceof GalCard) {
			return $node->getACL();
		}
		if (($node instanceof Calendar || $node instanceof CalendarObject) && $this->isReadOnly($node)) {
			return [
				[
					'privilege' => '{DAV:}read',
					'principal' => '{DAV:}authenticated',
					'protected' => true,
				],
			];
		}

		return [
			[
				'privilege' => '{DAV:}all',
				'principal' => '{DAV:}authenticated',
				'protected' => true,
			],
		];
	}

	/**
	 * Sabre leaves out the write privileges of calendars the backend marks read-only.
	 *
	 * @param Calendar|CalendarObject $node
	 *
	 * @return bool
	 */
	private function isReadOnly($node) {
		foreach ($node->getACL() as $ace) {
			if (in_array($ace['privilege'], ['{DAV:}all', '{DAV:}write'], true)) {
				return false;
			}
		}

		return true;
	}
}
