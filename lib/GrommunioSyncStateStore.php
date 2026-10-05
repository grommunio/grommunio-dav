<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2026 grommunio GmbH
 *
 * Keeps the sync state in the store of the logged on user.
 *
 * States and URLs are associated messages in the hidden folder
 * "GD-SyncState" below the root of the store, as grommunio-sync does it.
 * Deleting that folder resets all sync states of the user; clients then
 * sync their collections again.
 */

namespace grommunio\DAV;

class GrommunioSyncStateStore {
	public const FOLDER_NAME = 'GD-SyncState';
	private const STATE_CLASS = 'IPM.grommunio-dav.SyncState';
	private const URL_CLASS = 'IPM.grommunio-dav.SyncUrl';
	// States unused for this long are removed, except for the most recent
	// ones; a client with such an old token syncs the collection again.
	private const STATE_AGE = 30 * 86400;
	private const KEEP_STATES = 100;
	private const MAX_STATES = 2000;

	private $logger;
	private $gDavBackend;
	private $store;
	private $folder;
	private $properties;
	private $urls = [];

	/**
	 * Constructor.
	 *
	 * @param GLogger $logger
	 */
	public function __construct($logger, GrommunioDavBackend $gDavBackend) {
		$this->logger = $logger;
		$this->gDavBackend = $gDavBackend;
	}

	/**
	 * Fetch state information for a folderId (e.g. calenderId) and an id (uuid).
	 *
	 * @param string $folderid
	 * @param string $id
	 *
	 * @return null|string
	 */
	public function getState($folderid, $id) {
		$row = $this->findRow(self::STATE_CLASS, ['folder' => $folderid, 'token' => $id], [], true);
		if (!isset($row[PR_ENTRYID])) {
			return null;
		}
		// tables may cut long values, read it from the message
		$message = mapi_msgstore_openentry($this->store, $row[PR_ENTRYID]);
		$props = $message ? mapi_getprops($message, [PR_BODY]) : [];

		return $props[PR_BODY] ?? null;
	}

	/**
	 * Set state information for a folderId (e.g. calenderId) and an id (uuid).
	 * The state information is the sync token for ICS.
	 *
	 * @param string $folderid
	 * @param string $id
	 * @param string $value
	 */
	public function setState($folderid, $id, $value) {
		$this->write(self::STATE_CLASS, ['folder' => $folderid, 'token' => $id], [PR_BODY => $value]);
		$this->prune($folderid);
	}

	/**
	 * Set the APPTTSREF (custom URL) for a folderId and source key.
	 * This is needed for detecting the URL of deleted items reported by ICS.
	 *
	 * @param string $folderid
	 * @param string $sourcekey
	 * @param string $appttsref
	 */
	public function rememberAppttsref($folderid, $sourcekey, $appttsref) {
		$urls = $this->getUrls($folderid);
		if (($urls[$sourcekey] ?? null) === $appttsref) {
			return;
		}
		$this->write(self::URL_CLASS, ['folder' => $folderid, 'sourcekey' => $sourcekey], ['url' => $appttsref]);
		$this->urls[$folderid][$sourcekey] = $appttsref;
	}

	/**
	 * Get the APPTTSREF (custom URL) for a folderId and source key.
	 * This is needed for detecting the URL of deleted items reported by ICS.
	 *
	 * @param string $folderid
	 * @param string $sourcekey
	 *
	 * @return null|string
	 */
	public function getAppttsref($folderid, $sourcekey) {
		return $this->getUrls($folderid)[$sourcekey] ?? null;
	}

	/**
	 * Get the sourcekey from the saved APPTTSREF (custom URL) and a folderId.
	 * This is the last resort when searching for an item in the store fails.
	 *
	 * @param string $folderid
	 * @param string $appttsref
	 *
	 * @return null|string
	 */
	public function getSourcekey($folderid, $appttsref) {
		$row = $this->findRow(self::URL_CLASS, ['folder' => $folderid, 'url' => $appttsref], ['sourcekey']);

		return $row['sourcekey'] ?? null;
	}

	/**
	 * The token only changes when a client syncs, not when the folder does,
	 * so it is not offered as the current one of a collection: clients
	 * comparing it would miss changes.
	 *
	 * @param string $folderId
	 */
	public function getCurrentToken($folderId) {
		return null;
	}

	/**
	 * Returns all URLs of a folder, by source key.
	 *
	 * @param string $folderid
	 *
	 * @return array
	 */
	private function getUrls($folderid) {
		if (!isset($this->urls[$folderid])) {
			$this->urls[$folderid] = [];
			foreach ($this->findRows(self::URL_CLASS, ['folder' => $folderid], ['sourcekey', 'url']) as $row) {
				if (isset($row['sourcekey'], $row['url'])) {
					$this->urls[$folderid][$row['sourcekey']] = $row['url'];
				}
			}
		}

		return $this->urls[$folderid];
	}

	/**
	 * Creates or updates the message identified by @keys.
	 *
	 * @param string $class
	 * @param array  $keys   property name => value
	 * @param array  $values property name => value
	 */
	private function write($class, array $keys, array $values) {
		$folder = $this->getFolder(true);
		if ($folder === null) {
			return;
		}
		$properties = $this->getProperties();
		$row = $this->findRow($class, $keys, [], true);
		$message = isset($row[PR_ENTRYID]) ?
			mapi_msgstore_openentry($this->store, $row[PR_ENTRYID]) :
			mapi_folder_createmessage($folder, MAPI_ASSOCIATED);
		if (!$message) {
			$this->logger->error("Unable to store sync state: 0x%08X", mapi_last_hresult());

			return;
		}
		$props = [PR_MESSAGE_CLASS => $class];
		foreach ($keys + $values as $name => $value) {
			$props[$properties[$name] ?? $name] = $value;
		}
		mapi_setprops($message, $props);
		if (!mapi_savechanges($message)) {
			$this->logger->error("Unable to save sync state: 0x%08X", mapi_last_hresult());
		}
	}

	/**
	 * Deletes the states of a folder not used for a while.
	 *
	 * @param string $folderid
	 */
	private function prune($folderid) {
		$rows = $this->findRows(self::STATE_CLASS, ['folder' => $folderid], [], true);
		if (count($rows) <= self::KEEP_STATES) {
			return;
		}
		usort($rows, fn ($a, $b) => ($b[PR_LAST_MODIFICATION_TIME] ?? 0) <=> ($a[PR_LAST_MODIFICATION_TIME] ?? 0));
		$expired = time() - self::STATE_AGE;
		$entryids = [];
		foreach (array_slice($rows, self::KEEP_STATES, null, true) as $i => $row) {
			if ($i >= self::MAX_STATES || ($row[PR_LAST_MODIFICATION_TIME] ?? 0) < $expired) {
				$entryids[] = $row[PR_ENTRYID];
			}
		}
		if (!empty($entryids)) {
			mapi_folder_deletemessages($this->getFolder(), $entryids, DELETE_HARD_DELETE);
		}
	}

	/**
	 * Returns the first row matching @keys.
	 *
	 * @param string $class
	 * @param array  $columns property names
	 * @param bool   $entryid also return PR_ENTRYID
	 *
	 * @return null|array
	 */
	private function findRow($class, array $keys, array $columns, $entryid = false) {
		return $this->findRows($class, $keys, $columns, $entryid)[0] ?? null;
	}

	/**
	 * Returns the rows matching @keys, with property names as keys.
	 *
	 * @param string $class
	 * @param array  $columns property names
	 * @param bool   $entryid also return PR_ENTRYID and PR_LAST_MODIFICATION_TIME
	 *
	 * @return array
	 */
	private function findRows($class, array $keys, array $columns, $entryid = false) {
		$folder = $this->getFolder();
		if ($folder === null) {
			return [];
		}
		$properties = $this->getProperties();
		$restriction = [[RES_PROPERTY, [RELOP => RELOP_EQ, ULPROPTAG => PR_MESSAGE_CLASS, VALUE => $class]]];
		foreach ($keys as $name => $value) {
			$restriction[] = [RES_PROPERTY, [RELOP => RELOP_EQ, ULPROPTAG => $properties[$name], VALUE => $value]];
		}
		$table = mapi_folder_getcontentstable($folder, MAPI_ASSOCIATED | MAPI_DEFERRED_ERRORS);
		if (!$table || !mapi_table_restrict($table, [RES_AND, $restriction])) {
			$this->logger->error("Unable to search sync state: 0x%08X", mapi_last_hresult());

			return [];
		}
		$tags = array_map(fn ($name) => $properties[$name], $columns);
		if ($entryid) {
			$tags[] = PR_ENTRYID;
			$tags[] = PR_LAST_MODIFICATION_TIME;
		}
		$rows = [];
		foreach (mapi_table_queryallrows($table, $tags) as $row) {
			$r = [];
			foreach ($columns as $name) {
				if (isset($row[$properties[$name]])) {
					$r[$name] = $row[$properties[$name]];
				}
			}
			if ($entryid) {
				$r[PR_ENTRYID] = $row[PR_ENTRYID];
				$r[PR_LAST_MODIFICATION_TIME] = $row[PR_LAST_MODIFICATION_TIME] ?? 0;
			}
			$rows[] = $r;
		}

		return $rows;
	}

	/**
	 * Opens the state folder in the store of the logged on user.
	 *
	 * @param bool $create create it when missing
	 *
	 * @return mixed
	 */
	private function getFolder($create = false) {
		if ($this->folder !== null) {
			return $this->folder;
		}
		$session = $this->gDavBackend->GetSession();
		foreach (mapi_table_queryallrows(mapi_getmsgstorestable($session), [PR_DEFAULT_STORE, PR_ENTRYID]) as $row) {
			if (!empty($row[PR_DEFAULT_STORE])) {
				$this->store = mapi_openmsgstore($session, $row[PR_ENTRYID]);
			}
		}
		$root = $this->store ? mapi_msgstore_openentry($this->store) : false;
		if (!$root) {
			$this->logger->error("Unable to open the store for the sync state: 0x%08X", mapi_last_hresult());

			return null;
		}
		$folder = false;
		$hierarchy = mapi_folder_gethierarchytable($root, MAPI_DEFERRED_ERRORS);
		if ($hierarchy && mapi_table_restrict($hierarchy, [RES_PROPERTY, [RELOP => RELOP_EQ, ULPROPTAG => PR_DISPLAY_NAME, VALUE => self::FOLDER_NAME]])) {
			$rows = mapi_table_queryrows($hierarchy, [PR_ENTRYID], 0, 1);
			if (isset($rows[0][PR_ENTRYID])) {
				$folder = mapi_msgstore_openentry($this->store, $rows[0][PR_ENTRYID]);
			}
		}
		if (!$folder && !$create) {
			return null;
		}
		if (!$folder) {
			$folder = mapi_folder_createfolder($root, self::FOLDER_NAME, '');
			if ($folder) {
				mapi_setprops($folder, [PR_ATTR_HIDDEN => true]);
				// the tables of the new folder object are not usable yet
				$props = mapi_getprops($folder, [PR_ENTRYID]);
				$folder = isset($props[PR_ENTRYID]) ? mapi_msgstore_openentry($this->store, $props[PR_ENTRYID]) : false;
			}
		}
		if (!$folder) {
			$this->logger->error("Unable to open the sync state folder: 0x%08X", mapi_last_hresult());

			return null;
		}
		$this->folder = $folder;

		return $this->folder;
	}

	/**
	 * Returns the sync state properties.
	 *
	 * @return array
	 */
	private function getProperties() {
		if ($this->properties === null) {
			$this->properties = getPropIdsFromStrings($this->store, [
				'folder' => 'PT_STRING8:PSETID_GROMOX:dav-sync-folder',
				'token' => 'PT_STRING8:PSETID_GROMOX:dav-sync-token',
				'sourcekey' => 'PT_STRING8:PSETID_GROMOX:dav-sync-sourcekey',
				'url' => 'PT_STRING8:PSETID_GROMOX:dav-sync-url',
			]);
		}

		return $this->properties;
	}
}
