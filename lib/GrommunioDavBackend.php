<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2016 - 2018 Kopano b.v.
 * SPDX-FileCopyrightText: Copyright 2020 - 2026 grommunio GmbH
 *
 * grommunio DAV backend class which handles grommunio related activities.
 */

namespace grommunio\DAV;

use Sabre\CalDAV\Xml\Property\ScheduleCalendarTransp;
use Sabre\CalDAV\Xml\Property\SupportedCalendarComponentSet;
use Sabre\DAV\Exception as DAVException;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\Exception\NotFound;

class GrommunioDavBackend {
	public const IMPERSONATE_DELIM = '!';
	private $logger;
	protected $session;
	protected $stores;
	protected $user;
	protected $authUser;
	protected $customprops;
	protected $seeprivate;
	protected $syncstate;

	/**
	 * Constructor.
	 */
	public function __construct(GLogger $glogger) {
		$this->logger = $glogger;
		$this->syncstate = new GrommunioSyncStateStore($glogger, $this);
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
		$this->user = $this->authUser = $user;

		$gDavVersion = 'grommunio-dav' . @constant('GDAV_VERSION');
		$userAgent = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : 'unknown';
		if (defined('ALLOW_IMPERSONATE') && ALLOW_IMPERSONATE &&
		    stripos($user, self::IMPERSONATE_DELIM) !== false) {
			$parts = explode(self::IMPERSONATE_DELIM, $user);
			if (count($parts) === 2) {
				[$impersonatedUser, $authUser] = $parts;
				if ($impersonatedUser !== '' && strpos($authUser, '@') !== false) {
					$domainPos = strrpos($authUser, '@');
					$this->authUser = $authUser;
					$this->user = $impersonatedUser . substr($authUser, $domainPos);
				}
			}
			elseif (count($parts) === 3) {
				[$impersonatedUser, $impersonatedDomain, $authUser] = $parts;
				if ($impersonatedUser !== '' && $impersonatedDomain !== '' && strpos($authUser, '@') !== false) {
					$this->authUser = $authUser;
					$this->user = $impersonatedUser . '@' . $impersonatedDomain;
				}
			}
		}
		$this->session = mapi_logon_zarafa($this->authUser, $pass, MAPI_SERVER, null, null, 1, $gDavVersion, $userAgent);
		if (!$this->session) {
			$this->logger->info("Auth: ERROR - logon failed for user %s (%s) from IP %s", $this->authUser, $this->user, $_SERVER['REMOTE_ADDR']);

			return false;
		}

		$this->logger->debug("Auth: OK - user %s (auth user: %s) - session %s", $this->user, $this->authUser, $this->session);

		return $this->isGdavEnabled();
	}

	/**
	 * Returns the main user.
	 *
	 * @return string
	 */
	public function GetUser() {
		$this->logger->trace($this->user);

		return $this->user;
	}

	/**
	 * Returns the authenticated user.
	 *
	 * @return string
	 */
	public function GetAuthUser() {
		$this->logger->trace($this->authUser);

		return $this->authUser;
	}

	/**
	 * Create a folder with MAPI class.
	 *
	 * @param mixed  $principalUri
	 * @param string $url          the name and URI of the folder
	 * @param string $class
	 * @param string $comment
	 *
	 * @return string the folder id (principal:sourcekey)
	 */
	public function CreateFolder($principalUri, $url, $class, $comment) {
		$store = $this->GetStore($principalUri);
		if (!$store) {
			$this->throwOpenError(sprintf('Unable to open the store of %s', $principalUri));
		}
		$props = mapi_getprops($store, [PR_IPM_SUBTREE_ENTRYID]);
		$folder = mapi_msgstore_openentry($store, $props[PR_IPM_SUBTREE_ENTRYID]);
		if (!$folder) {
			$this->throwOpenError('Unable to open the IPM subtree');
		}
		$newfolder = mapi_folder_createfolder($folder, $url, $comment);
		if (!$newfolder) {
			$this->ThrowMapiError('Unable to create folder');
		}
		// the URI is kept when a client renames the folder
		$davProps = $this->GetFolderDavProperties($store);
		mapi_setprops($newfolder, [PR_CONTAINER_CLASS => $class, $davProps['davUri'] => $url]);
		// Return the composite folder id (principal:sourcekey) so callers that need to address the
		// freshly created folder via GetMapiFolder()/UpdateFolderProperties() can do so without
		// another round-trip. The original URL is still used by Sabre as the URI segment.
		$newprops = mapi_getprops($newfolder, [PR_SOURCE_KEY]);
		if (!isset($newprops[PR_SOURCE_KEY])) {
			$this->ThrowMapiError('Unable to get the source key of the new folder');
		}

		return $principalUri . ':' . bin2hex($newprops[PR_SOURCE_KEY]);
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
		if (!$store) {
			$this->throwOpenError(sprintf('Unable to open the store of %s', $principalUri));
		}
		$storeprops = mapi_getprops($store, [PR_IPM_WASTEBASKET_ENTRYID]);
		$rootfolder = mapi_msgstore_openentry($store);
		$hierarchy = mapi_folder_gethierarchytable($rootfolder, CONVENIENT_DEPTH | MAPI_DEFERRED_ERRORS);
		// TODO also filter hidden folders
		$restrictions = [];
		foreach ($classes as $class) {
			$restrictions[] = [RES_PROPERTY, [RELOP => RELOP_EQ, ULPROPTAG => PR_CONTAINER_CLASS, VALUE => $class]];
		}
		if (!mapi_table_restrict($hierarchy, [RES_OR, $restrictions])) {
			$this->ThrowMapiError('Unable to restrict the folder list');
		}

		$davProps = $this->GetFolderDavProperties($store);

		// TODO how to handle hierarchies?
		$queryCols = [PR_DISPLAY_NAME, PR_ENTRYID, PR_SOURCE_KEY, PR_PARENT_SOURCE_KEY, PR_FOLDER_TYPE, PR_LOCAL_COMMIT_TIME_MAX, PR_CONTAINER_CLASS, PR_COMMENT, PR_PARENT_ENTRYID, PR_RIGHTS];
		foreach ($davProps as $tag) {
			$queryCols[] = $tag;
		}
		$rows = mapi_table_queryallrows($hierarchy, $queryCols);

		$rootprops = mapi_getprops($rootfolder, [PR_IPM_CONTACT_ENTRYID, PR_IPM_APPOINTMENT_ENTRYID, PR_IPM_TASK_ENTRYID, PR_IPM_NOTE_ENTRYID]);

		// Try to open the folders directly if there are no rows in hierarchy table,
		// possibly top of the information store has no foldervisible permissions
		if (mapi_table_getrowcount($hierarchy) === 0) {
			$this->logger->debug("mapi_folder_gethierarchytable returned 0 entries, try opening folders directly");
			$rows = [];
			foreach ($classes as $class) {
				$folderEntryId = match($class) {
					'IPF.Contact' => $rootprops[PR_IPM_CONTACT_ENTRYID] ?? null,
					'IPF.Appointment' => $rootprops[PR_IPM_APPOINTMENT_ENTRYID] ?? null,
					'IPF.Task' => $rootprops[PR_IPM_TASK_ENTRYID] ?? null,
					Notes::CONTAINER_CLASS => $rootprops[PR_IPM_NOTE_ENTRYID] ?? null,
					default => null,
				};
				if ($folderEntryId !== null) {
					try {
						$subtreeFolder = mapi_msgstore_openentry($store, $folderEntryId);
						$rows[] = mapi_getprops($subtreeFolder, $queryCols);
					}
					catch (\Throwable $t) {
						$err = mapi_last_hresult();
						$this->logger->debug("Getting folder of class \"%s\" (entryid %s): %s (0x%x) - %s",
							$class, bin2hex($folderEntryId), mapi_strerror($err), $err, $t);
					}
				}
			}
		}
		$rows = array_filter($rows, function ($row) use ($storeprops) {
			if ($row[PR_FOLDER_TYPE] == FOLDER_SEARCH) {
				return false;
			}
			// visible without read permission, e.g. free/busy only
			if (isset($row[PR_RIGHTS]) && !($row[PR_RIGHTS] & (ecRightsReadAny | ecRightsFolderAccess))) {
				return false;
			}

			return !isset($row[PR_PARENT_ENTRYID], $storeprops[PR_IPM_WASTEBASKET_ENTRYID]) || $row[PR_PARENT_ENTRYID] != $storeprops[PR_IPM_WASTEBASKET_ENTRYID];
		});
		$uris = static::GetFolderUris($rows, $davProps['davUri'], array_values($rootprops));

		foreach ($rows as $row) {
			$folderId = $principalUri . ":" . bin2hex($row[PR_SOURCE_KEY]);
			$syncToken = $this->GetCurrentSyncToken($folderId);

			$folder = [
				'id' => $folderId,
				'uri' => $uris[$row[PR_SOURCE_KEY]],
				'principaluri' => $principalUri,
				'{http://sabredav.org/ns}sync-token' => $syncToken,
				'{DAV:}displayname' => $row[PR_DISPLAY_NAME],
				'{http://calendarserver.org/ns/}getctag' => isset($row[PR_LOCAL_COMMIT_TIME_MAX]) ? strval($row[PR_LOCAL_COMMIT_TIME_MAX]) : '0000000000',
			];
			// folders without a comment have no PR_COMMENT
			if (isset($row[PR_COMMENT])) {
				$folder['{urn:ietf:params:xml:ns:caldav}calendar-description'] = $row[PR_COMMENT];
				$folder['{urn:ietf:params:xml:ns:carddav}addressbook-description'] = $row[PR_COMMENT];
			}

			// set the supported component (task or calendar)
			if ($row[PR_CONTAINER_CLASS] == "IPF.Task") {
				$folder['{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set'] = new SupportedCalendarComponentSet(['VTODO']);
			}
			if ($row[PR_CONTAINER_CLASS] == "IPF.Appointment") {
				$folder['{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set'] = new SupportedCalendarComponentSet(['VEVENT']);
			}
			if ($row[PR_CONTAINER_CLASS] == Notes::CONTAINER_CLASS) {
				$folder['{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set'] = new SupportedCalendarComponentSet(['VJOURNAL']);
				if (isset($row[PR_RIGHTS]) && !($row[PR_RIGHTS] & (ecRightsCreate | ecRightsEditOwned | ecRightsEditAny | ecRightsDeleteOwned | ecRightsDeleteAny | ecRightsFolderAccess))) {
					$folder['{http://sabredav.org/ns}read-only'] = true;
				}
			}

			// Apple-specific folder metadata (calendar-color, calendar-order) stored in PSETID_GROMOX.
			// Apple Calendar requests these on every PROPFIND and PROPPATCHes them on calendar creation/edit.
			if (isset($row[$davProps['calendarColor']])) {
				$folder['{http://apple.com/ns/ical/}calendar-color'] = $row[$davProps['calendarColor']];
			}
			if (isset($row[$davProps['calendarOrder']])) {
				$folder['{http://apple.com/ns/ical/}calendar-order'] = (int) $row[$davProps['calendarOrder']];
			}
			// schedule-calendar-transp only applies to calendar folders (RFC 6638).
			if (in_array($row[PR_CONTAINER_CLASS], ['IPF.Appointment', 'IPF.Task'], true)) {
				$transp = isset($row[$davProps['calendarTransp']]) && $row[$davProps['calendarTransp']] ? 'transparent' : 'opaque';
				$folder['{urn:ietf:params:xml:ns:caldav}schedule-calendar-transp'] = new ScheduleCalendarTransp($transp);
				// clients show the calendar read-only if the folder permissions allow no changes
				if (isset($row[PR_RIGHTS]) && !($row[PR_RIGHTS] & (ecRightsCreate | ecRightsEditOwned | ecRightsEditAny | ecRightsDeleteOwned | ecRightsDeleteAny | ecRightsFolderAccess))) {
					$folder['{http://sabredav.org/ns}read-only'] = true;
				}
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
	 * Returns the URIs of folders.
	 *
	 * The URI of a folder is its name, or the name it had when a client
	 * renamed it, so that its URL stays the same. Names with "/" and
	 * names taken by another folder cannot be used, these folders are
	 * addressed by their source key. A name is kept by a folder with a
	 * kept URI first, then by a default folder, then by the lowest source
	 * key.
	 *
	 * @param array $rows       folder properties incl. PR_SOURCE_KEY, PR_ENTRYID, PR_DISPLAY_NAME and $uriTag
	 * @param int   $uriTag     tag of the named property with the URI
	 * @param array $defaultIds entry ids of the default folders
	 *
	 * @return array URI by source key
	 */
	public static function GetFolderUris(array $rows, $uriTag, array $defaultIds = []) {
		$candidates = [];
		foreach ($rows as $row) {
			$pinned = isset($row[$uriTag]) && $row[$uriTag] !== '';
			$candidates[] = [
				'sourcekey' => $row[PR_SOURCE_KEY],
				'uri' => (string) ($pinned ? $row[$uriTag] : ($row[PR_DISPLAY_NAME] ?? '')),
				'pinned' => $pinned,
				'default' => isset($row[PR_ENTRYID]) && in_array($row[PR_ENTRYID], $defaultIds, true),
			];
		}
		usort($candidates, fn ($a, $b) => [$b['pinned'], $b['default']] <=> [$a['pinned'], $a['default']] ?: strcmp($a['sourcekey'], $b['sourcekey']));

		$uris = [];
		$taken = [];
		foreach ($candidates as $candidate) {
			$uri = $candidate['uri'];
			if ($uri === '' || $uri === '.' || $uri === '..' || strpos($uri, '/') !== false || isset($taken[$uri])) {
				$uri = bin2hex($candidate['sourcekey']);
			}
			$taken[$uri] = true;
			$uris[$candidate['sourcekey']] = $uri;
		}

		return $uris;
	}

	/**
	 * Returns the URI of a folder as listed by GetFolders().
	 *
	 * @param string $folderId
	 * @param array  $classes  container classes of the listing
	 *
	 * @return null|string
	 */
	public function GetFolderUri($folderId, $classes) {
		$principalUri = explode(':', $folderId, 2)[0];
		foreach ($this->GetFolders($principalUri, $classes) as $folder) {
			if ($folder['id'] === $folderId) {
				return $folder['uri'];
			}
		}

		return null;
	}

	/**
	 * Resolves MAPI named property tags for the Apple/DAV folder metadata stored in PSETID_GROMOX.
	 *
	 * @param mixed $store MAPI store
	 *
	 * @return array keys: calendarColor, calendarOrder, calendarTransp, davUri
	 */
	public function GetFolderDavProperties($store) {
		return getPropIdsFromStrings($store, [
			"calendarColor" => MapiProps::PROP_CALENDAR_COLOR,
			"calendarOrder" => MapiProps::PROP_CALENDAR_ORDER,
			"calendarTransp" => MapiProps::PROP_CALENDAR_TRANSP,
			"davUri" => MapiProps::PROP_DAV_URI,
		]);
	}

	/**
	 * Applies a PROPPATCH-derived set of folder properties to the MAPI folder backing $folderId.
	 *
	 * When the folder is renamed, its current URI is kept for it.
	 *
	 * gromox refuses changes of folder properties unless the user owns the
	 * store or the folder. Clients like Apple Calendar set their color and
	 * order also on shared calendars and retry endlessly on errors, these
	 * changes are skipped without an error. Only a refused rename is
	 * reported, the folder keeps its name then.
	 *
	 * @param string      $folderId
	 * @param array       $propsToSet    property tag => value
	 * @param array       $propsToDelete property tags to delete
	 * @param null|string $uri           current URI of the folder, required to rename it unless it was created over DAV
	 *
	 * @return array tags of the properties not applied, empty on success
	 */
	public function UpdateFolderProperties($folderId, array $propsToSet, array $propsToDelete = [], $uri = null) {
		$folder = $this->GetMapiFolder($folderId);
		$failed = [];
		if (isset($propsToSet[PR_DISPLAY_NAME])) {
			$uriTag = $this->GetFolderDavProperties($this->GetStoreById($folderId))['davUri'];
			$props = mapi_getprops($folder, [PR_DISPLAY_NAME, $uriTag]);
			if ($propsToSet[PR_DISPLAY_NAME] === ($props[PR_DISPLAY_NAME] ?? null)) {
				unset($propsToSet[PR_DISPLAY_NAME]);
			}
			elseif (!isset($props[$uriTag])) {
				if ($uri === null) {
					$this->logger->error("Unable to rename folder %s without its URI", $folderId);
					unset($propsToSet[PR_DISPLAY_NAME]);
					$failed[] = PR_DISPLAY_NAME;
				}
				else {
					$propsToSet[$uriTag] = $uri;
				}
			}
		}
		if (empty($propsToSet) && empty($propsToDelete)) {
			return $failed;
		}
		$saved = (empty($propsToSet) || mapi_setprops($folder, $propsToSet)) &&
			(empty($propsToDelete) || mapi_deleteprops($folder, $propsToDelete)) &&
			mapi_savechanges($folder);
		if (!$saved) {
			$err = mapi_last_hresult();
			if ($err == MAPI_E_NO_ACCESS) {
				$this->logger->info("No permission to change the properties of folder %s, skipped", $folderId);

				return isset($propsToSet[PR_DISPLAY_NAME]) ? array_merge($failed, [PR_DISPLAY_NAME]) : $failed;
			}
			$this->logger->error("Unable to change the properties of folder %s: %s (0x%x)", $folderId, mapi_strerror($err), $err);

			return array_merge($failed, array_keys($propsToSet), $propsToDelete);
		}
		// a name taken by another folder is not set, without an error
		if (isset($propsToSet[PR_DISPLAY_NAME])) {
			$props = mapi_getprops($folder, [PR_DISPLAY_NAME]);
			if (($props[PR_DISPLAY_NAME] ?? null) !== $propsToSet[PR_DISPLAY_NAME]) {
				$this->logger->info("Unable to rename folder %s to \"%s\"", $folderId, $propsToSet[PR_DISPLAY_NAME]);
				$failed[] = PR_DISPLAY_NAME;
			}
		}

		return $failed;
	}

	/**
	 * Returns the result of a PROPPATCH for PropPatch::handle().
	 *
	 * @param array $mutations clark-notation property name => value
	 * @param array $tags      MAPI property tag by clark-notation property name
	 * @param array $failed    tags of the properties not applied, see UpdateFolderProperties()
	 *
	 * @return array clark-notation property name => HTTP status
	 */
	public static function GetPropPatchResult(array $mutations, array $tags, array $failed) {
		$result = [];
		foreach ($mutations as $name => $value) {
			if (isset($tags[$name]) && in_array($tags[$name], $failed, true)) {
				$result[$name] = 403;
			}
			else {
				$result[$name] = $value === null ? 204 : 200;
			}
		}

		return $result;
	}

	/**
	 * Returns a MAPI restriction for a defined set of filters.
	 *
	 * @param array  $filters
	 * @param string $storeId (optional) mapi compatible storeid - required when using start+end filter
	 *
	 * @return null|array
	 */
	private function getRestrictionForFilters($filters, $storeId = null) {
		$hasTimerange = isset($filters['start'], $filters['end'], $storeId);
		$hasTypes = isset($filters['types']) && is_array($filters['types']) && !empty($filters['types']);

		// Fast path: only message-class filtering, no time-range.
		if (!$hasTimerange && $hasTypes) {
			$this->logger->trace("getRestrictionForFilters - types only: %s", $filters['types']);
			$arr = [];
			foreach ($filters['types'] as $filter) {
				$arr[] = [RES_PROPERTY,
					[RELOP => RELOP_EQ,
						ULPROPTAG => PR_MESSAGE_CLASS,
						VALUE => $filter,
					],
				];
			}
			$restriction = [RES_OR, $arr];
			$this->logger->trace("getRestrictionForFilters - built: %s", simplifyRestriction($restriction));

			return $restriction;
		}

		// Time-range only (no explicit types) – interpret as events time-range.
		if ($hasTimerange && !$hasTypes) {
			$this->logger->trace("getRestrictionForFilters - timerange only start:%d end:%d", $filters['start'], $filters['end']);
			$restriction = $this->GetCalendarRestriction($storeId, $filters['start'], $filters['end']);
			$this->logger->trace("getRestrictionForFilters - built: %s", simplifyRestriction($restriction));

			return $restriction;
		}

		// Both types and time-range. Apply date restriction to appointments only,
		// and include other types (e.g., tasks) without date constraints.
		if ($hasTimerange && $hasTypes) {
			$this->logger->trace("getRestrictionForFilters - timerange + types start:%d end:%d types:%s", $filters['start'], $filters['end'], $filters['types']);
			$orParts = [];

			$dateRestriction = $this->GetCalendarRestriction($storeId, $filters['start'], $filters['end']);
			foreach ($filters['types'] as $type) {
				if ($type === 'IPM.Appointment') {
					$orParts[] = [RES_AND, [
						$dateRestriction,
						[RES_PROPERTY, [RELOP => RELOP_EQ, ULPROPTAG => PR_MESSAGE_CLASS, VALUE => 'IPM.Appointment']],
					]];
				}
				else {
					// Other types (e.g., tasks) – no date constraint.
					$orParts[] = [RES_PROPERTY, [RELOP => RELOP_EQ, ULPROPTAG => PR_MESSAGE_CLASS, VALUE => $type]];
				}
			}

			// If no parts were added, return null.
			if (empty($orParts)) {
				return null;
			}

			$restriction = [RES_OR, $orParts];
			$this->logger->trace("getRestrictionForFilters - built: %s", simplifyRestriction($restriction));

			return $restriction;
		}

		return null;
	}

	/**
	 * Returns a list of objects for a folder given by the id.
	 *
	 * Besides the keys of Sabre, each object has the key "entryid" with
	 * the hex entry id of its message.
	 *
	 * @param string $id
	 * @param string $fileExtension
	 * @param array  $filters       see getRestrictionForFilters(), "uid" restricts to calendar objects with this UID
	 *
	 * @return array
	 */
	public function GetObjects($id, $fileExtension, $filters = []) {
		$folder = $this->GetMapiFolder($id);
		$properties = $this->GetCustomProperties($id);
		$table = mapi_folder_getcontentstable($folder, MAPI_DEFERRED_ERRORS);
		$restriction = $this->getRestrictionForFilters($filters, $this->GetStoreById($id));
		if (isset($filters['uid'])) {
			$goidRestriction = $this->getGoidRestriction($id, $filters['uid']);
			if ($goidRestriction === null) {
				return [];
			}
			$restriction = $restriction ? [RES_AND, [$restriction, $goidRestriction]] : $goidRestriction;
		}
		if ($restriction && !mapi_table_restrict($table, $restriction)) {
			$this->ThrowMapiError('Unable to restrict the object list');
		}

		$rows = mapi_table_queryallrows($table, [PR_ENTRYID, PR_SOURCE_KEY, PR_LAST_MODIFICATION_TIME, PR_MESSAGE_SIZE, $properties['goid'], PR_SENSITIVITY, $properties['private']]);

		$results = [];
		foreach ($rows as $row) {
			$realId = "";
			if (isset($row[$properties['goid']])) {
				$realId = getUidFromGoid($row[$properties['goid']]);
			}
			if (!$realId) {
				$realId = bin2hex($row[PR_SOURCE_KEY]);
			}
			$realId = rawurlencode($realId);
			// masked private objects get an ETag of their own
			$hidden = $fileExtension == GrommunioCalDavBackend::FILE_EXTENSION && $this->IsPrivateHidden($id, $row);

			$result = [
				'id' => $realId,
				'uri' => $realId . $fileExtension,
				'etag' => '"' . $row[PR_LAST_MODIFICATION_TIME] . ($hidden ? '-p' : '') . '"',
				'lastmodified' => $row[PR_LAST_MODIFICATION_TIME],
				'size' => $row[PR_MESSAGE_SIZE], // only approximation
				'entryid' => bin2hex($row[PR_ENTRYID]),
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
		if (!$mapimessage) {
			// gromox answers a missing create permission with MAPI_E_NOT_FOUND
			if (mapi_last_hresult() == MAPI_E_NOT_FOUND) {
				throw new Forbidden('Permission denied to create the object');
			}
			$this->ThrowMapiError('Unable to create object');
		}
		// we save the objectId in PROP_APPTTSREF so we find it by this id
		$properties = $this->GetCustomProperties($folderId);
		// FIXME: uid for contacts
		$goid = getGoidFromUid($objectId);
		mapi_setprops($mapimessage, [$properties['goid'] => $goid]);

		return $mapimessage;
	}

	/**
	 * Throws the DAV exception for the last MAPI error.
	 *
	 * @param string $message
	 *
	 * @throws DAVException
	 */
	public function ThrowMapiError($message) {
		$err = mapi_last_hresult();
		$this->logger->error("%s: %s (0x%x)", $message, mapi_strerror($err), $err);
		if ($err == MAPI_E_NO_ACCESS) {
			throw new Forbidden($message);
		}

		throw new DAVException($message);
	}

	/**
	 * Throws the DAV exception for an object that cannot be opened.
	 *
	 * @param string $message
	 *
	 * @throws DAVException
	 */
	private function throwOpenError($message) {
		$err = mapi_last_hresult();
		$this->logger->info("%s: %s (0x%x)", $message, mapi_strerror($err), $err);
		if ($err == MAPI_E_NO_ACCESS) {
			throw new Forbidden($message);
		}

		throw new NotFound($message);
	}

	/**
	 * Returns a mapi folder resource for a folderid (principal:PR_SOURCE_KEY).
	 *
	 * @param string $folderid
	 *
	 * @return mixed
	 *
	 * @throws DAVException if the folder cannot be opened
	 */
	public function GetMapiFolder($folderid) {
		$this->logger->trace('Id: %s', $folderid);
		$arr = explode(':', $folderid, 2);
		$store = $this->GetStore($arr[0]);
		$sourcekey = isset($arr[1]) && ctype_xdigit($arr[1]) && strlen($arr[1]) % 2 == 0 ? hex2bin($arr[1]) : false;
		// an empty entry id would open the root folder
		$entryid = $store && $sourcekey ? mapi_msgstore_entryidfromsourcekey($store, $sourcekey) : false;
		$folder = $entryid ? mapi_msgstore_openentry($store, $entryid) : false;
		if (!$folder) {
			$this->throwOpenError(sprintf('Unable to open folder %s', $folderid));
		}

		return $folder;
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
		if ($username == $this->GetAuthUser() && $defaultstore != null) {
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

		/* We already got the store, under this or its SMTP name */
		$key = strtolower($storename);
		if (isset($this->stores[$key])) {
			return $this->stores[$key];
		}

		$store = $this->OpenMapiStore($storename);
		if (!$store) {
			$err = mapi_last_hresult();
			$this->logger->info("Auth: Unable to open store for \"%s\": %s (0x%x)",
				$storename, mapi_strerror($err), $err);
			return false;
		}

		// g-dav#61: always use SMTP address (issue with altnames)
		$storeProps = mapi_getprops($store, [PR_MAILBOX_OWNER_ENTRYID]);
		$mailuser = isset($storeProps[PR_MAILBOX_OWNER_ENTRYID]) ? mapi_ab_openentry($this->GetAddressBook(), $storeProps[PR_MAILBOX_OWNER_ENTRYID]) : false;
		$smtpProps = $mailuser ? mapi_getprops($mailuser, [PR_SMTP_ADDRESS]) : [];
		if (isset($smtpProps[PR_SMTP_ADDRESS])) {
			// only the logged on user, not the owners of other stores
			if (strcasecmp($storename, $this->user) == 0) {
				$this->user = $smtpProps[PR_SMTP_ADDRESS];
			}
			$this->stores[strtolower($smtpProps[PR_SMTP_ADDRESS])] = $store;
		}
		$this->stores[$key] = $store;

		return $store;
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
				return rawurlencode($id);
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

		$id = rawurldecode($this->GetObjectIdFromObjectUri($objectUri, $extension));

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
			$restriction = [];

			if ($extension) {
				if ($extension == GrommunioCalDavBackend::FILE_EXTENSION) {
					$this->logger->trace("Try goid %s", $id);
					$uids = [$id];
					// Sometimes Thunderbird urlencodes the URI part
					if (urldecode($id) !== $id) {
						$uids[] = urldecode($id);
					}
					// In some cases Thunderbird replaces "@"-sign in UID with an underscore "_" in the URI part, e.g.:
					// PUT 12345678-ABCD_bahn.de.ics
					// UID:12345678-ABCD@bahn.de
					$underscoreCnt = substr_count($id, '_');
					if ($underscoreCnt === 1) {
						$uids[] = str_replace('_', '@', $id);
					}
					$goidRestriction = $this->getGoidRestriction($folderId, $uids);
					if ($goidRestriction !== null) {
						$restriction[] = $goidRestriction;
					}
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
			// an unrestricted table would hand out some other message
			if (mapi_table_restrict($table, [RES_OR, $restriction])) {
				// Get requested properties, plus whatever we need
				$proplist = [PR_ENTRYID];
				$rows = mapi_table_queryallrows($table, $proplist);
			}
			else {
				$err = mapi_last_hresult();
				$this->logger->error("Table restriction for search keyword \"%s\" failed: %s (0x%x)",
					$id, mapi_strerror($err), $err);
				$rows = [];
			}
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
		if (!$entryid && $extension == GrommunioCalDavBackend::FILE_EXTENSION) {
			// last resort: derive the uid from each message's goid like GetObjects()
			// does, stored goids may deviate from the reconstructed ones
			$this->logger->debug("Try scanning the folder for a message with a goid matching uid '%s'", $id);
			$properties = $this->GetCustomProperties($folderId);
			$table = mapi_folder_getcontentstable($mapifolder, MAPI_DEFERRED_ERRORS);
			$rows = mapi_table_queryallrows($table, [PR_ENTRYID, $properties['goid']]);
			foreach ($rows as $row) {
				if (isset($row[$properties['goid']]) && getUidFromGoid($row[$properties['goid']]) === $id) {
					$entryid = $row[PR_ENTRYID];
					$this->logger->debug("Found message by goid folder scan, entry id: %s", bin2hex($entryid));

					break;
				}
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
	 * Returns a restriction for messages with a goid of one of the UIDs.
	 *
	 * @param string       $folderId
	 * @param array|string $uids
	 *
	 * @return null|array null if no goid can be derived
	 */
	private function getGoidRestriction($folderId, $uids) {
		$properties = $this->GetCustomProperties($folderId);
		$restrictions = [];
		foreach ((array) $uids as $uid) {
			foreach ([getGoidFromUid($uid), getGoidFromUidZero($uid)] as $goid) {
				// an empty value would match unrelated messages carrying an empty goid property
				if (!is_string($goid) || $goid === '') {
					continue;
				}
				$this->logger->trace("Try goid 0x%08X => %s", $properties["goid"], bin2hex($goid));
				$restrictions[] = [RES_PROPERTY, [RELOP => RELOP_EQ, ULPROPTAG => $properties["goid"], VALUE => $goid]];
			}
		}

		return empty($restrictions) ? null : [RES_OR, $restrictions];
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
	public function GetCustomProperties($id) {
		if (!isset($this->customprops[$id])) {
			$this->logger->trace("Fetching properties id:%s", $id);
			$store = $this->GetStoreById($id);
			$properties = getPropIdsFromStrings($store, [
				"goid" => "PT_BINARY:PSETID_Meeting:" . PidLidGlobalObjectId,
				"vcarduid" => MapiProps::PROP_VCARDUID,
				"private" => "PT_BOOLEAN:PSETID_Common:" . PidLidPrivate,
			]);
			$this->customprops[$id] = $properties;
		}

		return $this->customprops[$id];
	}

	/**
	 * Checks whether a private message is hidden from the user. As in
	 * grommunio-web, private items of other stores are only visible to
	 * delegates allowed to see them.
	 *
	 * @param string $folderId
	 * @param array  $props    message properties incl. PR_SENSITIVITY and the private property
	 *
	 * @return bool
	 */
	public function IsPrivateHidden($folderId, $props) {
		$private = $this->GetCustomProperties($folderId)['private'];
		if (empty($props[$private]) && ($props[PR_SENSITIVITY] ?? SENSITIVITY_NONE) != SENSITIVITY_PRIVATE) {
			return false;
		}
		$storeId = explode(':', $folderId)[0];
		if (!isset($this->seeprivate[$storeId])) {
			$this->seeprivate[$storeId] = $this->canSeePrivate($this->GetStoreById($folderId));
		}

		return !$this->seeprivate[$storeId];
	}

	/**
	 * Checks the delegate flags of the store owner for the user.
	 *
	 * @param mixed $store
	 *
	 * @return bool
	 */
	private function canSeePrivate($store) {
		$props = mapi_getprops($store, [PR_MDB_PROVIDER, PR_USER_ENTRYID]);
		if (($props[PR_MDB_PROVIDER] ?? '') !== ZARAFA_STORE_DELEGATE_GUID) {
			return true;
		}

		// might not be accessible, e.g. without permissions on the freebusy data
		try {
			$fbmessage = \FreeBusy::getLocalFreeBusyMessage($store);
		}
		catch (\Throwable $t) {
			$this->logger->debug("Unable to open the local freebusy message: %s", $t->getMessage());
			$fbmessage = false;
		}
		if (!$fbmessage) {
			return false;
		}
		$fbprops = mapi_getprops($fbmessage, [PR_SCHDINFO_DELEGATE_ENTRYIDS, PR_DELEGATE_FLAGS]);
		$index = array_search($props[PR_USER_ENTRYID] ?? null, $fbprops[PR_SCHDINFO_DELEGATE_ENTRYIDS] ?? [], true);

		return $index !== false && ($fbprops[PR_DELEGATE_FLAGS][$index] ?? 0) == 1;
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
	public function GetCalendarRestriction($store, $start, $end) {
		$props = getPropIdsFromStrings($store, MapiProps::GetAppointmentProperties());

		return getCalendarRestriction($props, $start, $end);
	}

	/**
	 * Performs ICS based sync used from getChangesForAddressBook
	 * / getChangesForCalendar.
	 *
	 * @param string      $folderId
	 * @param null|string $syncToken
	 * @param string      $fileExtension
	 * @param null|int    $limit
	 * @param array       $filters
	 *
	 * @return null|array null if the sync token is unknown or the changes cannot be exported
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
		if ($syncToken == null || $syncToken == '0000000000') {
			mapi_stream_write($stream, hex2bin("0000000000000000"));
		}
		else {
			$value = $this->syncstate->getState($arr[1], $syncToken);
			if ($value === null) {
				// e.g. removed after a long time or from before the update
				$this->logger->info("Unknown sync token %s for %s, the client syncs again", $syncToken, $folderId);

				return null;
			}
			mapi_stream_write($stream, hex2bin($value));
		}

		// force restriction of "types" to export only appointments or contacts
		$restriction = $this->getRestrictionForFilters($filters);

		// The last parameter in mapi_exportchanges_config is buffer size for mapi_exportchanges_synchronize - how many
		// changes will be processed in its call. Setting it to MAX_SYNC_ITEMS won't export more items than is set in
		// the config. If there are more changes than MAX_SYNC_ITEMS the result is marked as truncated, the client
		// then syncs the rest with the returned token in subsequent requests.
		$bufferSize = ($limit !== null && $limit > 0) ? $limit : MAX_SYNC_ITEMS;
		mapi_exportchanges_config($exporter, $stream, SYNC_NORMAL | SYNC_UNICODE, $mapiimporter, $restriction, false, false, $bufferSize);
		$changesCount = mapi_exportchanges_getchangecount($exporter);
		$this->logger->debug("Exporter found %d changes, buffer size for mapi_exportchanges_synchronize %d", $changesCount, $bufferSize);
		$truncated = false;
		while (is_array(mapi_exportchanges_synchronize($exporter))) {
			if ($changesCount > $bufferSize) {
				$this->logger->info("There were too many changes to be exported in this request. Total changes %d, exported %d.", $changesCount, $phpwrapper->Total());
				$truncated = true;

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

		// an initial sync gets a token of its own, also without any objects
		$initial = $syncToken == null || $syncToken == '0000000000';
		$newtoken = ($phpwrapper->Total() > 0 || $initial) ? uniqid() : $syncToken;

		$this->syncstate->setState($arr[1], $newtoken, bin2hex($state));

		$result = [
			"syncToken" => $newtoken,
			"added" => $phpwrapper->GetAdded(),
			"modified" => $phpwrapper->GetModified(),
			"deleted" => $phpwrapper->GetDeleted(),
			"result_truncated" => $truncated,
		];

		$this->logger->trace("Returning %s", $result);

		return $result;
	}

	/**
	 * Returns an array of necessary properties to set with default values.
	 *
	 * @see MapiProps::GetDefault...Properties()
	 *
	 * @param mixed $id           storeid
	 * @param mixed $mapimessage  mapi message to check
	 * @param array $propList     array of mapped properties
	 * @param array $defaultProps array of necessary properties with default values
	 *
	 * @return array
	 */
	public function GetPropsToSet($id, $mapimessage, $propList, $defaultProps) {
		$propsToSet = [];
		$store = $this->GetStoreById($id);
		$propList = getPropIdsFromStrings($store, $propList);
		$props = mapi_getprops($mapimessage);

		foreach ($defaultProps as $prop => $value) {
			if (!isset($props[$propList[$prop]])) {
				$propsToSet[$propList[$prop]] = $value;
			}
		}

		return $propsToSet;
	}

	/**
	 * Returns the current sync-token for the folder if one was issued.
	 *
	 * @param string $folderId composite id in form principal:sourcekey
	 *
	 * @return string
	 */
	public function GetCurrentSyncToken($folderId) {
		$arr = explode(':', $folderId, 2);
		if (count($arr) < 2 || $arr[1] === '') {
			return '0000000000';
		}

		$token = $this->syncstate->getCurrentToken($arr[1]);

		return (!is_string($token) || $token === '') ? '0000000000' : $token;
	}

	/**
	 * Checks whether the user is enabled for grommunio-dav.
	 *
	 * @return bool
	 */
	private function isGdavEnabled() {
		$store = $this->GetStore($this->GetUser());
		if (!$store) {
			return false;
		}
		$storeProps = mapi_getprops($store, [PR_EC_ENABLED_FEATURES_L]);
		if (($storeProps[PR_EC_ENABLED_FEATURES_L] ?? 0) & UP_DAV) {
			$this->logger->debug("user %s is enabled for grommunio-dav", $this->user);

			return true;
		}
		$this->logger->debug("user %s is disabled for grommunio-dav", $this->user);

		return false;
	}
}
