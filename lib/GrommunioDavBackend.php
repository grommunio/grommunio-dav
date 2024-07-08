<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2016 - 2018 Kopano b.v.
 * SPDX-FileCopyrightText: Copyright 2020 - 2024 grommunio GmbH
 *
 * grommunio DAV backend class which handles grommunio related activities.
 */

namespace grommunio\DAV;

use Sabre\CalDAV\Xml\Property\SupportedCalendarComponentSet;

class GrommunioDavBackend {
	private $logger;
	protected $session;
	protected $stores;
	protected $user;
	protected $customprops;
	protected $syncstate;

	/**
	 * Constructor.
	 */
	public function __construct(GLogger $glogger) {
		$this->logger = $glogger;
		$this->syncstate = new GrommunioSyncState($glogger, SYNC_DB);
	}

	/**
	 * Connect to grommunio and create session.
	 *
	 * @param string $user
	 * @param string $pass
	 *
	 * @return bool
	 */
	public function Logon($user, $pass) {
		$this->logger->trace('%s / password', $user);

		$gDavVersion = 'grommunio-dav' . @constant('GDAV_VERSION');
		$userAgent = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : 'unknown';
		$this->session = mapi_logon_zarafa($user, $pass, MAPI_SERVER, null, null, 1, $gDavVersion, $userAgent);
		if (!$this->session) {
			$this->logger->info("Auth: ERROR - logon failed for user %s", $user);

			return false;
		}

		$this->user = $user;
		$this->logger->debug("Auth: OK - user %s - session %s", $this->user, $this->session);

		return true;
	}

	/**
	 * Returns the authenticated user.
	 *
	 * @return string
	 */
	public function GetUser() {
		$this->logger->trace($this->user);

		return $this->user;
	}

	/**
	 * Create a folder with MAPI class.
	 *
	 * @param mixed  $principalUri
	 * @param string $url
	 * @param string $class
	 * @param string $displayname
	 *
	 * @return string
	 */
	public function CreateFolder($principalUri, $url, $class, $displayname) {
		$props = mapi_getprops($this->GetStore($principalUri), [PR_IPM_SUBTREE_ENTRYID]);
		$folder = mapi_msgstore_openentry($this->GetStore($principalUri), $props[PR_IPM_SUBTREE_ENTRYID]);
		$newfolder = mapi_folder_createfolder($folder, $url, $displayname);
		mapi_setprops($newfolder, [PR_CONTAINER_CLASS => $class]);

		return $url;
	}

	/**
	 * Delete a folder with MAPI class.
	 *
	 * @param mixed $id
	 *
	 * @return bool
	 */
	public function DeleteFolder($id) {
		$folder = $this->GetMapiFolder($id);
		if (!$folder) {
			return false;
		}

		$props = mapi_getprops($folder, [PR_ENTRYID, PR_PARENT_ENTRYID]);
		$parentfolder = mapi_msgstore_openentry($this->GetStoreById($id), $props[PR_PARENT_ENTRYID]);
		mapi_folder_deletefolder($parentfolder, $props[PR_ENTRYID]);

		return true;
	}

	/**
	 * Returns a list of folders for a MAPI class.
	 *
	 * @param string $principalUri
	 * @param mixed  $classes
	 *
	 * @return array
	 */
	public function GetFolders($principalUri, $classes) {
		$this->logger->trace("principal '%s', classes '%s'", $principalUri, $classes);
		$folders = [];

		// TODO limit the output to subfolders of the principalUri?

		$store = $this->GetStore($principalUri);
		$storeprops = mapi_getprops($store, [PR_IPM_WASTEBASKET_ENTRYID]);
		$rootfolder = mapi_msgstore_openentry($store);
		$hierarchy = mapi_folder_gethierarchytable($rootfolder, CONVENIENT_DEPTH | MAPI_DEFERRED_ERRORS);
		// TODO also filter hidden folders
		$restrictions = [];
		foreach ($classes as $class) {
			$restrictions[] = [RES_PROPERTY, [RELOP => RELOP_EQ, ULPROPTAG => PR_CONTAINER_CLASS, VALUE => $class]];
		}
		mapi_table_restrict($hierarchy, [RES_OR, $restrictions]);

		// TODO how to handle hierarchies?
		$rows = mapi_table_queryallrows($hierarchy, [PR_DISPLAY_NAME, PR_ENTRYID, PR_SOURCE_KEY, PR_PARENT_SOURCE_KEY, PR_FOLDER_TYPE, PR_LOCAL_COMMIT_TIME_MAX, PR_CONTAINER_CLASS, PR_COMMENT, PR_PARENT_ENTRYID]);

		$rootprops = mapi_getprops($rootfolder, [PR_IPM_CONTACT_ENTRYID, PR_IPM_APPOINTMENT_ENTRYID]);
		foreach ($rows as $row) {
			if ($row[PR_FOLDER_TYPE] == FOLDER_SEARCH) {
				continue;
			}

			if (isset($row[PR_PARENT_ENTRYID], $storeprops[PR_IPM_WASTEBASKET_ENTRYID]) && $row[PR_PARENT_ENTRYID] == $storeprops[PR_IPM_WASTEBASKET_ENTRYID]) {
				continue;
			}

			$folder = [
				'id' => $principalUri . ":" . bin2hex($row[PR_SOURCE_KEY]),
				'uri' => $row[PR_DISPLAY_NAME],
				'principaluri' => $principalUri,
				'{http://sabredav.org/ns}sync-token' => '0000000000',
				'{DAV:}displayname' => $row[PR_DISPLAY_NAME],
				'{urn:ietf:params:xml:ns:caldav}calendar-description' => $row[PR_COMMENT],
				'{http://calendarserver.org/ns/}getctag' => isset($row[PR_LOCAL_COMMIT_TIME_MAX]) ? strval($row[PR_LOCAL_COMMIT_TIME_MAX]) : '0000000000',
			];

			// set the supported component (task or calendar)
			if ($row[PR_CONTAINER_CLASS] == "IPF.Task") {
				$folder['{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set'] = new SupportedCalendarComponentSet(['VTODO']);
			}
			if ($row[PR_CONTAINER_CLASS] == "IPF.Appointment") {
				$folder['{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set'] = new SupportedCalendarComponentSet(['VEVENT']);
			}

			// ensure default contacts folder is put first, some clients
			// i.e. Apple Addressbook only supports one contact folder,
			// therefore it is desired that folder is the default one.
			if (in_array("IPF.Contact", $classes) && isset($rootprops[PR_IPM_CONTACT_ENTRYID]) && $row[PR_ENTRYID] == $rootprops[PR_IPM_CONTACT_ENTRYID]) {
				array_unshift($folders, $folder);
			}
			// ensure default calendar folder is put first,
			// before the tasks folder.
			elseif (in_array('IPF.Appointment', $classes) && isset($rootprops[PR_IPM_APPOINTMENT_ENTRYID]) && $row[PR_ENTRYID] == $rootprops[PR_IPM_APPOINTMENT_ENTRYID]) {
				array_unshift($folders, $folder);
			}
			else {
				array_push($folders, $folder);
			}
		}
		$this->logger->trace('found %d folders: %s', count($folders), $folders);

		return $folders;
	}

	/**
	 * Returns a MAPI restriction for a defined set of filters.
	 *
	 * @param array  $filters
	 * @param string $storeId (optional) mapi compatible storeid - required when using start+end filter
	 *
	 * @return array|null
	 */
	private function getRestrictionForFilters($filters, $storeId = null) {
		$restrictions = [];
		if (isset($filters['start'], $filters['end'], $storeId)) {
			$this->logger->trace("getRestrictionForFilters - got start: %d and end: %d", $filters['start'], $filters['end']);
			$subrestriction = $this->GetCalendarRestriction($storeId, $filters['start'], $filters['end']);
			$restrictions[] = $subrestriction;
		}
		if (isset($filters['types'])) {
			$this->logger->trace("getRestrictionForFilters - got types: %s", $filters['types']);
			$arr = [];
			foreach ($filters['types'] as $filter) {
				$arr[] = [RES_PROPERTY,
					[RELOP => RELOP_EQ,
						ULPROPTAG => PR_MESSAGE_CLASS,
						VALUE => $filter,
					],
				];
			}
			$restrictions[] = [RES_OR, $arr];
		}
		if (!empty($restrictions)) {
			$restriction = [RES_AND, $restrictions];
			$this->logger->trace("getRestrictionForFilters - got restriction: %s", $restriction);

			return $restriction;
		}

		return null;
	}

	/**
	 * Returns a list of objects for a folder given by the id.
	 *
	 * @param string $id
	 * @param string $fileExtension
	 * @param array  $filters
	 *
	 * @return array
	 */
	public function GetObjects($id, $fileExtension, $filters = []) {
		$folder = $this->GetMapiFolder($id);
		$properties = $this->GetCustomProperties($id);
		$table = mapi_folder_getcontentstable($folder, MAPI_DEFERRED_ERRORS);
		$restriction = $this->getRestrictionForFilters($filters, $this->GetStoreById($id));
		if ($restriction) {
			mapi_table_restrict($table, $restriction);
		}

		$rows = mapi_table_queryallrows($table, [PR_SOURCE_KEY, PR_LAST_MODIFICATION_TIME, PR_MESSAGE_SIZE, $properties['goid']]);

		$results = [];
		foreach ($rows as $row) {
			$realId = "";
			if (isset($row[$properties['goid']])) {
				$realId = getUidFromGoid($row[$properties['goid']]);
			}
			if (!$realId) {
				$realId = bin2hex($row[PR_SOURCE_KEY]);
			}

			$result = [
				'id' => $realId,
				'uri' => $realId . $fileExtension,
				'etag' => '"' . $row[PR_LAST_MODIFICATION_TIME] . '"',
				'lastmodified' => $row[PR_LAST_MODIFICATION_TIME],
				'size' => $row[PR_MESSAGE_SIZE], // only approximation
			];

			if ($fileExtension == GrommunioCalDavBackend::FILE_EXTENSION) {
				$result['calendarid'] = $id;
			}
			elseif ($fileExtension == GrommunioCardDavBackend::FILE_EXTENSION) {
				$result['addressbookid'] = $id;
			}
			$results[] = $result;
		}

		return $results;
	}

	/**
	 * Create the object and set appttsref.
	 *
	 * @param mixed  $folderId
	 * @param mixed  $folder
	 * @param string $objectId
	 *
	 * @return mixed
	 */
	public function CreateObject($folderId, $folder, $objectId) {
		$mapimessage = mapi_folder_createmessage($folder);
		// we save the objectId in PROP_APPTTSREF so we find it by this id
		$properties = $this->GetCustomProperties($folderId);
		// FIXME: uid for contacts
		$goid = getGoidFromUid($objectId);
		mapi_setprops($mapimessage, [$properties['goid'] => $goid]);

		return $mapimessage;
	}

	/**
	 * Returns a mapi folder resource for a folderid (PR_SOURCE_KEY).
	 *
	 * @param string $folderid
	 *
	 * @return mixed
	 */
	public function GetMapiFolder($folderid) {
		$this->logger->trace('Id: %s', $folderid);
		$arr = explode(':', $folderid);
		$entryid = mapi_msgstore_entryidfromsourcekey($this->GetStore($arr[0]), hex2bin($arr[1]));

		return mapi_msgstore_openentry($this->GetStore($arr[0]), $entryid);
	}

	/**
	 * Returns MAPI addressbook.
	 *
	 * @return mixed
	 */
	public function GetAddressBook() {
		// TODO should be a singleton
		return mapi_openaddressbook($this->session);
	}

	/**
	 * Opens MAPI store for the user.
	 *
	 * @param string $username
	 *
	 * @return mixed
	 */
	public function OpenMapiStore($username = null) {
		$msgstorestable = mapi_getmsgstorestable($this->session);
		$msgstores = mapi_table_queryallrows($msgstorestable, [PR_DEFAULT_STORE, PR_ENTRYID, PR_MDB_PROVIDER]);

		$defaultstore = null;
		$publicstore = null;
		foreach ($msgstores as $row) {
			if (isset($row[PR_DEFAULT_STORE]) && $row[PR_DEFAULT_STORE]) {
				$defaultstore = $row[PR_ENTRYID];
			}
			if (isset($row[PR_MDB_PROVIDER]) && $row[PR_MDB_PROVIDER] == ZARAFA_STORE_PUBLIC_GUID) {
				$publicstore = $row[PR_ENTRYID];
			}
		}

		/* user's own store or public store */
		if ($username == $this->GetUser() && $defaultstore != null) {
			return mapi_openmsgstore($this->session, $defaultstore);
		}
		if ($username == 'public' && $publicstore != null) {
			return mapi_openmsgstore($this->session, $publicstore);
		}

		/* otherwise other user's store */
		$store = mapi_openmsgstore($this->session, $defaultstore);
		if (!$store) {
			return false;
		}
		$otherstore = mapi_msgstore_createentryid($store, $username);

		return mapi_openmsgstore($this->session, $otherstore);
	}

	/**
	 * Returns store for the user.
	 *
	 * @param string $storename
	 *
	 * @return mixed
	 */
	public function GetStore($storename) {
		if ($storename == null) {
			$storename = $this->GetUser();
		}
		else {
			$storename = str_replace('principals/', '', $storename);
		}
		$this->logger->trace("storename %s", $storename);

		/* We already got the store */
		if (isset($this->stores[$storename]) && $this->stores[$storename] != null) {
			return $this->stores[$storename];
		}

		$this->stores[$storename] = $this->OpenMapiStore($storename);
		if (!$this->stores[$storename]) {
			$this->logger->info("Auth: ERROR - unable to open store for %s (0x%08X)", $storename, mapi_last_hresult());

			return false;
		}

		return $this->stores[$storename];
	}

	/**
	 * Returns store from the id.
	 *
	 * @param mixed $id
	 *
	 * @return mixed
	 */
	public function GetStoreById($id) {
		$arr = explode(':', $id);

		return $this->GetStore($arr[0]);
	}

	/**
	 * Returns logon session.
	 *
	 * @return mixed
	 */
	public function GetSession() {
		return $this->session;
	}

	/**
	 * Returns an object ID of a mapi object.
	 * If set, goid will be preferred. If not the PR_SOURCE_KEY of the message (as hex) will be returned.
	 *
	 * This order is reflected as well when searching for a message with these ids in GrommunioDavBackend->GetMapiMessageForId().
	 *
	 * @param string $folderId
	 * @param mixed  $mapimessage
	 *
	 * @return string
	 */
	public function GetIdOfMapiMessage($folderId, $mapimessage) {
		$this->logger->trace("Finding ID of %s", $mapimessage);
		$properties = $this->GetCustomProperties($folderId);

		// It's one of these, order:
		// - GOID (if set)
		// - PROP_VCARDUID (if set)
		// - PR_SOURCE_KEY
		$props = mapi_getprops($mapimessage, [$properties['goid'], PR_SOURCE_KEY]);
		if (isset($props[$properties['goid']])) {
			$id = getUidFromGoid($props[$properties['goid']]);
			$this->logger->debug("Found uid %s from goid: %s", $id, bin2hex($props[$properties['goid']]));
			if ($id != null) {
				return $id;
			}
		}
		// PR_SOURCE_KEY is always available
		$id = bin2hex($props[PR_SOURCE_KEY]);
		$this->logger->debug("Found PR_SOURCE_KEY: %s", $id);

		return $id;
	}

	/**
	 * Finds and opens a MapiMessage from an objectId.
	 * The id can be a PROP_APPTTSREF or a PR_SOURCE_KEY (as hex).
	 *
	 * @param string $folderId
	 * @param string $objectUri
	 * @param mixed  $mapifolder optional
	 * @param string $extension  optional
	 *
	 * @return mixed
	 */
	public function GetMapiMessageForId($folderId, $objectUri, $mapifolder = null, $extension = null) {
		$this->logger->trace("Searching for '%s' in '%s' (%s) (%s)", $objectUri, $folderId, $mapifolder, $extension);

		if (!$mapifolder) {
			$mapifolder = $this->GetMapiFolder($folderId);
		}

		$id = $this->GetObjectIdFromObjectUri($objectUri, $extension);

		/* The ID can be several different things:
		 * - a UID that is saved in goid
		 * - a PROP_VCARDUID
		 * - a PR_SOURCE_KEY
		 *
		 * If it's a sourcekey, we can open the message directly.
		 * If the $extension is set:
		 *      if it's ics:
		 *          - search GOID with this value
		 *      if it's vcf:
		 *          - search PROP_VCARDUID value
		 */
		$entryid = false;
		$restriction = false;

		if (ctype_xdigit($id) && strlen($id) % 2 == 0) {
			$this->logger->trace("Try PR_SOURCE_KEY %s", $id);
			$arr = explode(':', $folderId);
			$entryid = mapi_msgstore_entryidfromsourcekey($this->GetStoreById($arr[0]), hex2bin($arr[1]), hex2bin($id));
		}

		if (!$entryid) {
			$this->logger->trace("Entryid not found. Try goid/vcarduid %s", $id);

			$properties = $this->GetCustomProperties($folderId);
			if (strpos($id, '%40') !== false) {
				$this->logger->debug("The id contains '%40'. Use urldecode.");
				$id = urldecode($id);
			}
			$restriction = [];

			if ($extension) {
				if ($extension == GrommunioCalDavBackend::FILE_EXTENSION) {
					$this->logger->trace("Try goid %s", $id);
					$goid = getGoidFromUid($id);
					$this->logger->trace("Try goid 0x%08X => %s", $properties["goid"], bin2hex($goid));
					$goid0 = getGoidFromUidZero($id);
					$restriction[] = [RES_OR, [
						[RES_PROPERTY, [RELOP => RELOP_EQ, ULPROPTAG => $properties["goid"], VALUE => $goid]],
						[RES_PROPERTY, [RELOP => RELOP_EQ, ULPROPTAG => $properties["goid"], VALUE => $goid0]],
					]];
				}
				elseif ($extension == GrommunioCardDavBackend::FILE_EXTENSION) {
					$this->logger->trace("Try vcarduid %s", $id);
					$restriction[] = [RES_PROPERTY, [RELOP => RELOP_EQ, ULPROPTAG => $properties["vcarduid"], VALUE => $id]];
				}
			}
		}

		// find the message if we have a restriction
		if ($restriction) {
			$table = mapi_folder_getcontentstable($mapifolder, MAPI_DEFERRED_ERRORS);
			mapi_table_restrict($table, [RES_OR, $restriction]);
			// Get requested properties, plus whatever we need
			$proplist = [PR_ENTRYID];
			$rows = mapi_table_queryallrows($table, $proplist);
			if (count($rows) > 1) {
				$this->logger->warn("Found %d entries for id '%s' searching for message, returnin first in the list", count($rows), $id);
			}
			if (isset($rows[0], $rows[0][PR_ENTRYID])) {
				$entryid = $rows[0][PR_ENTRYID];
			}
		}
		if (!$entryid) {
			$this->logger->debug("Try to get entryid from appttsref");
			$arr = explode(':', $folderId);
			$sk = $this->syncstate->getSourcekey($arr[1], $id);
			if ($sk !== null) {
				$this->logger->debug("Found sourcekey from appttsref %s", $sk);
				$entryid = mapi_msgstore_entryidfromsourcekey($this->GetStoreById($arr[0]), hex2bin($arr[1]), hex2bin($sk));
			}
		}
		if ($entryid) {
			$mapimessage = mapi_msgstore_openentry($this->GetStoreById($folderId), $entryid);
			if (!$mapimessage) {
				$this->logger->warn("Error, unable to open entry id: %s 0x%X", bin2hex($entryid), mapi_last_hresult());

				return null;
			}

			return $mapimessage;
		}
		$this->logger->debug("Nothing found for %s", $id);

		return null;
	}

	/**
	 * Returns the objectId from an objectUri. It strips the file extension
	 * if it matches the passed one.
	 *
	 * @param string $objectUri
	 * @param string $extension
	 *
	 * @return string
	 */
	public function GetObjectIdFromObjectUri($objectUri, $extension) {
		if (!$extension) {
			return $objectUri;
		}
		$extLength = strlen($extension);
		if (substr($objectUri, -$extLength) === $extension) {
			return substr($objectUri, 0, -$extLength);
		}

		return $objectUri;
	}

	/**
	 * Checks if the PHP-MAPI extension is available and in a requested version.
	 *
	 * @param string $version the version to be checked ("6.30.10-18495", parts or build number)
	 *
	 * @return bool installed version is superior to the checked string
	 */
	protected function checkMapiExtVersion($version = "") {
		if (!extension_loaded("mapi")) {
			return false;
		}
		// compare build number if requested
		if (preg_match('/^\d+$/', $version) && strlen($version) > 3) {
			$vs = preg_split('/-/', phpversion("mapi"));

			return $version <= $vs[1];
		}
		if (version_compare(phpversion("mapi"), $version) == -1) {
			return false;
		}

		return true;
	}

	/**
	 * Get named (custom) properties. Currently only PROP_APPTTSREF.
	 *
	 * @param string $id the folder id
	 *
	 * @return mixed
	 */
	protected function GetCustomProperties($id) {
		if (!isset($this->customprops[$id])) {
			$this->logger->trace("Fetching properties id:%s", $id);
			$store = $this->GetStoreById($id);
			$properties = getPropIdsFromStrings($store, ["goid" => "PT_BINARY:PSETID_Meeting:0x3", "vcarduid" => MapiProps::PROP_VCARDUID]);
			$this->customprops[$id] = $properties;
		}

		return $this->customprops[$id];
	}

	/**
	 * Create a MAPI restriction to use in the calendar which will
	 * return future calendar items (until $end), plus those since $start.
	 * Origins: Z-Push.
	 *
	 * @param mixed $store the MAPI store
	 * @param int   $start Timestamp since when to include messages
	 * @param int   $end   Ending timestamp
	 *
	 * @return array
	 */
	// TODO getting named properties
	public function GetCalendarRestriction($store, $start, $end) {
		$props = MapiProps::GetAppointmentProperties();
		$props = getPropIdsFromStrings($store, $props);

		return [RES_OR,
			[
				// OR
				// item.end > window.start && item.start < window.end
				[RES_AND,
					[
						[RES_PROPERTY,
							[RELOP => RELOP_LE,
								ULPROPTAG => $props["starttime"],
								VALUE => $end,
							],
						],
						[RES_PROPERTY,
							[RELOP => RELOP_GE,
								ULPROPTAG => $props["endtime"],
								VALUE => $start,
							],
						],
					],
				],
				// OR
				[RES_OR,
					[
						// OR
						// (EXIST(recurrence_enddate_property) && item[isRecurring] == true && recurrence_enddate_property >= start)
						[RES_AND,
							[
								[RES_EXIST,
									[ULPROPTAG => $props["recurrenceend"],
									],
								],
								[RES_PROPERTY,
									[RELOP => RELOP_EQ,
										ULPROPTAG => $props["isrecurring"],
										VALUE => true,
									],
								],
								[RES_PROPERTY,
									[RELOP => RELOP_GE,
										ULPROPTAG => $props["recurrenceend"],
										VALUE => $start,
									],
								],
							],
						],
						// OR
						// (!EXIST(recurrence_enddate_property) && item[isRecurring] == true && item[start] <= end)
						[RES_AND,
							[
								[RES_NOT,
									[
										[RES_EXIST,
											[ULPROPTAG => $props["recurrenceend"],
											],
										],
									],
								],
								[RES_PROPERTY,
									[RELOP => RELOP_LE,
										ULPROPTAG => $props["starttime"],
										VALUE => $end,
									],
								],
								[RES_PROPERTY,
									[RELOP => RELOP_EQ,
										ULPROPTAG => $props["isrecurring"],
										VALUE => true,
									],
								],
							],
						],
					],
				], // EXISTS OR
			],
		];        // global OR
	}

	/**
	 * Performs ICS based sync used from getChangesForAddressBook
	 * / getChangesForCalendar.
	 *
	 * @param string $folderId
	 * @param string $syncToken
	 * @param string $fileExtension
	 * @param int    $limit
	 * @param array  $filters
	 *
	 * @return array|null
	 */
	public function Sync($folderId, $syncToken, $fileExtension, $limit = null, $filters = []) {
		$arr = explode(':', $folderId);
		$phpwrapper = new PHPWrapper($this->GetStoreById($folderId), $this->logger, $this->GetCustomProperties($folderId), $fileExtension, $this->syncstate, $arr[1]);
		$mapiimporter = mapi_wrap_importcontentschanges($phpwrapper);

		$mapifolder = $this->GetMapiFolder($folderId);
		$exporter = mapi_openproperty($mapifolder, PR_CONTENTS_SYNCHRONIZER, IID_IExchangeExportChanges, 0, 0);
		if (!$exporter) {
			$this->logger->error("Unable to get exporter");

			return null;
		}

		$stream = mapi_stream_create();
		if ($syncToken == null) {
			mapi_stream_write($stream, hex2bin("0000000000000000"));
		}
		else {
			$value = $this->syncstate->getState($arr[1], $syncToken);
			if ($value === null) {
				$this->logger->error("Unable to get value from token: %s - folderId: %s", $syncToken, $folderId);

				return null;
			}
			mapi_stream_write($stream, hex2bin($value));
		}

		// force restriction of "types" to export only appointments or contacts
		$restriction = $this->getRestrictionForFilters($filters);

		// The last parameter in mapi_exportchanges_config is buffer size for mapi_exportchanges_synchronize - how many
		// changes will be processed in its call. Setting it to MAX_SYNC_ITEMS won't export more items than is set in
		// the config. If there are more changes than MAX_SYNC_ITEMS the client will eventually catch up and sync
		// the rest on the subsequent sync request(s).
		$bufferSize = ($limit !== null && $limit > 0) ? $limit : MAX_SYNC_ITEMS;
		mapi_exportchanges_config($exporter, $stream, SYNC_NORMAL | SYNC_UNICODE, $mapiimporter, $restriction, false, false, $bufferSize);
		$changesCount = mapi_exportchanges_getchangecount($exporter);
		$this->logger->debug("Exporter found %d changes, buffer size for mapi_exportchanges_synchronize %d", $changesCount, $bufferSize);
		while (is_array(mapi_exportchanges_synchronize($exporter))) {
			if ($changesCount > $bufferSize) {
				$this->logger->info("There were too many changes to be exported in this request. Total changes %d, exported %d.", $changesCount, $phpwrapper->Total());

				break;
			}
		}
		$exportedChanges = $phpwrapper->Total();
		$this->logger->debug("Exported %d changes, pending %d", $exportedChanges, $changesCount - $exportedChanges);

		mapi_exportchanges_updatestate($exporter, $stream);
		mapi_stream_seek($stream, 0, STREAM_SEEK_SET);
		$state = "";
		while (true) {
			$data = mapi_stream_read($stream, 4096);
			if (strlen($data) > 0) {
				$state .= $data;
			}
			else {
				break;
			}
		}

		$newtoken = ($phpwrapper->Total() > 0) ? uniqid() : $syncToken;

		$this->syncstate->setState($arr[1], $newtoken, bin2hex($state));

		$result = [
			"syncToken" => $newtoken,
			"added" => $phpwrapper->GetAdded(),
			"modified" => $phpwrapper->GetModified(),
			"deleted" => $phpwrapper->GetDeleted(),
		];

		$this->logger->trace("Returning %s", $result);

		return $result;
	}
}
