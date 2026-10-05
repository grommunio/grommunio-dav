<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2016 - 2018 Kopano b.v.
 * SPDX-FileCopyrightText: Copyright 2020 - 2025 grommunio GmbH
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
	 * @param mixed                   $store
	 * @param GLogger                 $logger
	 * @param mixed                   $props
	 * @param string                  $fileext
	 * @param GrommunioSyncStateStore $syncstate
	 * @param string                  $folderid
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
	 * @param int    $flags
	 * @param object $retmapimessage
	 *
	 * @return int
	 */
	public function ImportMessageChange($props, $flags, $retmapimessage) {
		$entryid = $props[PR_ENTRYID] ?? null;
		// if the entryid is not available, do the fallback to the sourcekey
		if (!$entryid && isset($props[PR_SOURCE_KEY], $props[PR_PARENT_SOURCE_KEY])) {
			$entryid = mapi_msgstore_entryidfromsourcekey($this->store, $props[PR_PARENT_SOURCE_KEY], $props[PR_SOURCE_KEY]);
		}
		$mapimessage = $entryid ? mapi_msgstore_openentry($this->store, $entryid) : false;

		$url = null;
		if ($mapimessage) {
			$messageProps = mapi_getprops($mapimessage, [PR_SOURCE_KEY, $this->props["goid"]]);
			$sourcekey = $messageProps[PR_SOURCE_KEY];
			if (isset($messageProps[$this->props["goid"]])) {
				// get uid from goid and check if it's a valid one
				$url = getUidFromGoid($messageProps[$this->props["goid"]]);
				if ($url != null) {
					$this->logger->trace("got %s (goid: %s uid: %s), flags: %d", bin2hex($sourcekey), bin2hex($messageProps[$this->props["goid"]]), $url, $flags);
					$this->syncstate->rememberAppttsref($this->folderid, bin2hex($sourcekey), $url);
				}
			}
		}
		elseif (isset($props[PR_SOURCE_KEY])) {
			// e.g. deleted meanwhile, report it under the name known
			$sourcekey = $props[PR_SOURCE_KEY];
			$this->logger->debug("Unable to open message %s: 0x%x", bin2hex($sourcekey), mapi_last_hresult());
			$url = $this->syncstate->getAppttsref($this->folderid, bin2hex($sourcekey));
		}
		else {
			$this->logger->warn("Unable to open message without source key: 0x%x", mapi_last_hresult());

			return SYNC_E_IGNORE;
		}
		if (!$url) {
			$this->logger->trace("got %s (PR_SOURCE_KEY), flags: %d", bin2hex($sourcekey), $flags);
			$url = bin2hex($sourcekey);
		}
		$url = rawurlencode($url);

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
	 * @param int   $flags
	 * @param array $sourcekeys array with sourcekeys
	 */
	public function ImportMessageDeletion($flags, $sourcekeys) {
		foreach ($sourcekeys as $sourcekey) {
			$this->logger->trace("got %s", bin2hex($sourcekey));
			$appttsref = $this->syncstate->getAppttsref($this->folderid, bin2hex($sourcekey));
			// the same name as reported by ImportMessageChange()
			if ($appttsref !== null) {
				$this->deleted[] = rawurlencode($appttsref) . $this->fileext;
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
