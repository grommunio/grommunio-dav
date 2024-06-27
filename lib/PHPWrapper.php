<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2016 - 2018 Kopano b.v.
 * SPDX-FileCopyrightText: Copyright 2020-2024 grommunio GmbH
 *
 * PHP wrapper class for ICS.
 */

namespace grommunio\DAV;

class PHPWrapper {
	private $store;
	private $logger;
	private $props;
	private $fileext;
	private $added;
	private $modified;
	private $deleted;
	private $syncstate;
	private $folderid;

	/**
	 * Constructor.
	 *
	 * @param MAPIStore          $store
	 * @param GLogger            $logger
	 * @param mixed              $props
	 * @param string             $fileext
	 * @param GrommunioSyncState $syncstate
	 * @param string             $folderid
	 */
	public function __construct($store, $logger, $props, $fileext, $syncstate, $folderid) {
		$this->store = $store;
		$this->logger = $logger;
		$this->props = $props;
		$this->fileext = $fileext;
		$this->syncstate = $syncstate;
		$this->folderid = $folderid;

		$this->added = [];
		$this->modified = [];
		$this->deleted = [];
	}

	/**
	 * Accessor for $this->added.
	 *
	 * @return array
	 */
	public function GetAdded() {
		return $this->added;
	}

	/**
	 * Accessor for $this->modified.
	 *
	 * @return array
	 */
	public function GetModified() {
		return $this->modified;
	}

	/**
	 * Accessor for $this->deleted.
	 *
	 * @return array
	 */
	public function GetDeleted() {
		return $this->deleted;
	}

	/**
	 * Returns total changes.
	 *
	 * @return int
	 */
	public function Total() {
		return count($this->added) + count($this->modified) + count($this->deleted);
	}

	/**
	 * Imports a single message.
	 *
	 * @param array  $props
	 * @param long   $flags
	 * @param object $retmapimessage
	 *
	 * @return long
	 */
	public function ImportMessageChange($props, $flags, $retmapimessage) {
		$entryid = $props[PR_ENTRYID] ?? null;
		// if the entryid is not available, do the fallback to the sourcekey
		if (!$entryid && isset($props[PR_SOURCE_KEY], $props[PR_PARENT_SOURCE_KEY])) {
			$entryid = mapi_msgstore_entryidfromsourcekey($this->store, $props[PR_PARENT_SOURCE_KEY], $props[PR_SOURCE_KEY]);
		}
		$mapimessage = mapi_msgstore_openentry($this->store, $entryid);
		$messageProps = mapi_getprops($mapimessage, [PR_SOURCE_KEY, $this->props["goid"]]);

		$url = null;
		if (isset($messageProps[$this->props["goid"]])) {
			// get uid from goid and check if it's a valid one
			$url = getUidFromGoid($messageProps[$this->props["goid"]]);
			if ($url != null) {
				$this->logger->trace("got %s (goid: %s uid: %s), flags: %d", bin2hex($messageProps[PR_SOURCE_KEY]), bin2hex($messageProps[$this->props["goid"]]), $url, $flags);
				$this->syncstate->rememberAppttsref($this->folderid, bin2hex($messageProps[PR_SOURCE_KEY]), $url);
			}
		}
		if (!$url) {
			$this->logger->trace("got %s (PR_SOURCE_KEY), flags: %d", bin2hex($messageProps[PR_SOURCE_KEY]), $flags);
			$url = bin2hex($messageProps[PR_SOURCE_KEY]);
		}

		if ($flags == SYNC_NEW_MESSAGE) {
			$this->added[] = $url . $this->fileext;
		}
		else {
			$this->modified[] = $url . $this->fileext;
		}

		return SYNC_E_IGNORE;
	}

	/**
	 * Imports a list of messages to be deleted.
	 *
	 * @param long  $flags
	 * @param array $sourcekeys array with sourcekeys
	 */
	public function ImportMessageDeletion($flags, $sourcekeys) {
		foreach ($sourcekeys as $sourcekey) {
			$this->logger->trace("got %s", bin2hex($sourcekey));
			$appttsref = $this->syncstate->getAppttsref($this->folderid, bin2hex($sourcekey));
			if ($appttsref != null) {
				$this->deleted[] = $appttsref . $this->fileext;
			}
			else {
				$this->deleted[] = bin2hex($sourcekey) . $this->fileext;
			}
		}
	}

	/** Implement MAPI interface */
	public function Config($stream, $flags = 0) {}

	public function GetLastError($hresult, $ulflags, &$lpmapierror) {}

	public function UpdateState($stream) {}

	public function ImportMessageMove($sourcekeysrcfolder, $sourcekeysrcmessage, $message, $sourcekeydestmessage, $changenumdestmessage) {}

	public function ImportPerUserReadStateChange($readstates) {}

	public function ImportFolderChange($props) {
		return 0;
	}

	public function ImportFolderDeletion($flags, $sourcekeys) {
		return 0;
	}
}
