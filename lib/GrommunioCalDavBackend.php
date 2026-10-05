<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2016 - 2018 Kopano b.v.
 * SPDX-FileCopyrightText: Copyright 2020 - 2024 grommunio GmbH
 *
 * grommunio CalDAV backend class which handles calendar related activities.
 */

namespace grommunio\DAV;

use Sabre\CalDAV\Backend\AbstractBackend;
use Sabre\CalDAV\Backend\SchedulingSupport;
use Sabre\CalDAV\Backend\SyncSupport;
use Sabre\CalDAV\Xml\Property\ScheduleCalendarTransp;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\Exception\UnsupportedMediaType;
use Sabre\DAV\PropPatch;
use Sabre\VObject\Reader;

class GrommunioCalDavBackend extends AbstractBackend implements SchedulingSupport, SyncSupport {
	/*
	 * TODO IMPLEMENT
	 *
	 * SubscriptionSupport,
	 * SharingSupport,
	 *
	 */

	private $logger;
	protected $gDavBackend;
	protected $notes;
	// objects converted by calendarQuery(), by calendar id and URI
	private $queryObjects = [];

	public const FILE_EXTENSION = '.ics';
	// Include appointments, tasks and notes so all lists sync properly.
	public const MESSAGE_CLASSES = ['IPM.Appointment', 'IPM.Task', Notes::MESSAGE_CLASS];
	public const CONTAINER_CLASS = 'IPF.Appointment';
	public const CONTAINER_CLASSES = ['IPF.Appointment', 'IPF.Task', Notes::CONTAINER_CLASS];
	// kept when masking private objects
	private const MASK_KEEP_PROPERTIES = ['UID', 'DTSTAMP', 'CREATED', 'LAST-MODIFIED', 'SEQUENCE', 'DTSTART', 'DTEND', 'DUE', 'DURATION',
		'RRULE', 'RDATE', 'EXDATE', 'RECURRENCE-ID', 'TRANSP', 'STATUS', 'CLASS', 'X-MICROSOFT-CDO-BUSYSTATUS', 'X-MICROSOFT-CDO-ALLDAYEVENT'];

	/**
	 * Constructor.
	 */
	public function __construct(GrommunioDavBackend $gDavBackend, GLogger $glogger) {
		$this->gDavBackend = $gDavBackend;
		$this->logger = $glogger;
		$this->notes = new Notes($gDavBackend);
	}

	/**
	 * Returns a list of calendars for a principal.
	 *
	 * Every project is an array with the following keys:
	 *  * id, a unique id that will be used by other functions to modify the
	 *    calendar. This can be the same as the uri or a database key.
	 *  * uri. This is just the 'base uri' or 'filename' of the calendar.
	 *  * principaluri. The owner of the calendar. Almost always the same as
	 *    principalUri passed to this method.
	 *
	 * Furthermore it can contain webdav properties in clark notation. A very
	 * common one is '{DAV:}displayname'.
	 *
	 * Many clients also require:
	 * {urn:ietf:params:xml:ns:caldav}supported-calendar-component-set
	 * For this property, you can just return an instance of
	 * Sabre\CalDAV\Xml\Property\SupportedCalendarComponentSet.
	 *
	 * If you return {http://sabredav.org/ns}read-only and set the value to 1,
	 * ACL will automatically be put in read-only mode.
	 *
	 * @param string $principalUri
	 *
	 * @return array
	 */
	public function getCalendarsForUser($principalUri) {
		$this->logger->trace("principalUri: %s", $principalUri);

		return $this->gDavBackend->GetFolders($principalUri, static::CONTAINER_CLASSES);
	}

	/**
	 * Creates a new calendar for a principal.
	 *
	 * If the creation was a success, an id must be returned that can be used
	 * to reference this calendar in other methods, such as updateCalendar.
	 *
	 * @param string $principalUri
	 * @param string $calendarUri
	 * @param array  $properties   clark-notation property name => value
	 *
	 * @return string
	 */
	public function createCalendar($principalUri, $calendarUri, array $properties) {
		$this->logger->trace("principalUri: %s - calendarUri: %s - properties: %s", $principalUri, $calendarUri, $properties);

		// Determine requested component set to choose proper container class.
		$containerClass = static::CONTAINER_CLASS; // default to appointments
		$key = '{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set';
		if (isset($properties[$key]) && method_exists($properties[$key], 'getValue')) {
			$components = $properties[$key]->getValue();
			if (is_array($components)) {
				if (in_array('VTODO', $components, true)) {
					$containerClass = 'IPF.Task';
				}
				elseif (in_array('VJOURNAL', $components, true) && !in_array('VEVENT', $components, true)) {
					$containerClass = Notes::CONTAINER_CLASS;
				}
				elseif (in_array('VEVENT', $components, true)) {
					$containerClass = 'IPF.Appointment';
				}
			}
		}

		$folderId = $this->gDavBackend->CreateFolder($principalUri, $calendarUri, $containerClass, "");

		// Apply Apple/DAV metadata submitted during MKCALENDAR (color, order, displayname, transp).
		// The folder is named like the URI, which is kept when the displayname renames it.
		$this->applyCalendarProperties($folderId, $properties);

		return $folderId;
	}

	/**
	 * Updates properties for a calendar (PROPPATCH).
	 *
	 * Apple Calendar and iOS send PROPPATCH requests for {http://apple.com/ns/ical/}calendar-color and
	 * {http://apple.com/ns/ical/}calendar-order on every calendar it touches. Without this handler the
	 * Sabre default returns 403 Forbidden for each unknown property, which causes Apple clients to fall
	 * into a resync loop.
	 *
	 * @param string $calendarId
	 */
	public function updateCalendar($calendarId, PropPatch $propPatch) {
		$this->logger->trace("calendarId: %s", $calendarId);

		$supportedProperties = [
			'{DAV:}displayname',
			'{urn:ietf:params:xml:ns:caldav}calendar-description',
			'{urn:ietf:params:xml:ns:caldav}schedule-calendar-transp',
			'{http://apple.com/ns/ical/}calendar-color',
			'{http://apple.com/ns/ical/}calendar-order',
		];

		$propPatch->handle($supportedProperties, function ($mutations) use ($calendarId) {
			$uri = isset($mutations['{DAV:}displayname']) ? $this->gDavBackend->GetFolderUri($calendarId, static::CONTAINER_CLASSES) : null;

			return $this->applyCalendarProperties($calendarId, $mutations, $uri);
		});
	}

	/**
	 * Translates DAV/Apple calendar properties into MAPI properties and stores them on the folder.
	 *
	 * The displayname renames the folder, its URI is kept.
	 *
	 * @param string      $folderId
	 * @param array       $mutations map of clark-notation property name => value (null = remove)
	 * @param null|string $uri       current URI of the folder, required for the displayname of folders not created over DAV
	 *
	 * @return array clark-notation property name => HTTP status
	 */
	private function applyCalendarProperties($folderId, array $mutations, $uri = null) {
		if (empty($mutations)) {
			return [];
		}

		$store = $this->gDavBackend->GetStoreById($folderId);
		if (!$store) {
			return array_fill_keys(array_keys($mutations), 403);
		}
		$davProps = $this->gDavBackend->GetFolderDavProperties($store);

		$propsToSet = [];
		$propsToDelete = [];
		// MAPI property tag by clark-notation property name
		$tags = [];
		$result = [];

		foreach ($mutations as $propertyName => $propertyValue) {
			switch ($propertyName) {
				case '{DAV:}displayname':
					if ($propertyValue === null || $propertyValue === '') {
						$result[$propertyName] = 403;
						break;
					}
					$propsToSet[PR_DISPLAY_NAME] = (string) $propertyValue;
					$tags[$propertyName] = PR_DISPLAY_NAME;
					break;

				case '{urn:ietf:params:xml:ns:caldav}calendar-description':
					if ($propertyValue === null) {
						$propsToDelete[] = PR_COMMENT;
					}
					else {
						$propsToSet[PR_COMMENT] = (string) $propertyValue;
					}
					$tags[$propertyName] = PR_COMMENT;
					break;

				case '{http://apple.com/ns/ical/}calendar-color':
					if ($propertyValue === null || $propertyValue === '') {
						$propsToDelete[] = $davProps['calendarColor'];
					}
					else {
						$propsToSet[$davProps['calendarColor']] = (string) $propertyValue;
					}
					$tags[$propertyName] = $davProps['calendarColor'];
					break;

				case '{http://apple.com/ns/ical/}calendar-order':
					if ($propertyValue === null || $propertyValue === '') {
						$propsToDelete[] = $davProps['calendarOrder'];
					}
					else {
						$propsToSet[$davProps['calendarOrder']] = (int) (string) $propertyValue;
					}
					$tags[$propertyName] = $davProps['calendarOrder'];
					break;

				case '{urn:ietf:params:xml:ns:caldav}schedule-calendar-transp':
					$value = $propertyValue;
					if ($value instanceof ScheduleCalendarTransp) {
						$value = $value->getValue();
					}
					if ($value === null) {
						$propsToDelete[] = $davProps['calendarTransp'];
					}
					else {
						$propsToSet[$davProps['calendarTransp']] = ($value === 'transparent');
					}
					$tags[$propertyName] = $davProps['calendarTransp'];
					break;

				default:
					// Ignore properties handled elsewhere (e.g. supported-calendar-component-set
					// in createCalendar). updateCalendar funnels properties via PropPatch::handle(),
					// so only registered ones ever reach this path.
					break;
			}
		}

		$failed = $this->gDavBackend->UpdateFolderProperties($folderId, $propsToSet, $propsToDelete, $uri);

		return $result + GrommunioDavBackend::GetPropPatchResult($mutations, $tags, $failed);
	}

	/**
	 * Delete a calendar and all its objects.
	 *
	 * @param string $calendarId
	 */
	public function deleteCalendar($calendarId) {
		$this->logger->trace("calendarId: %s", $calendarId);
		$success = $this->gDavBackend->DeleteFolder($calendarId);
		// TODO evaluate $success
	}

	/**
	 * Returns all calendar objects within a calendar.
	 *
	 * Every item contains an array with the following keys:
	 *   * calendardata - The iCalendar-compatible calendar data
	 *   * uri - a unique key which will be used to construct the uri. This can
	 *     be any arbitrary string, but making sure it ends with '.ics' is a
	 *     good idea. This is only the basename, or filename, not the full
	 *     path.
	 *   * lastmodified - a timestamp of the last modification time
	 *   * etag - An arbitrary string, surrounded by double-quotes. (e.g.:
	 *   '  "abcdef"')
	 *   * size - The size of the calendar objects, in bytes.
	 *   * component - optional, a string containing the type of object, such
	 *     as 'vevent' or 'vtodo'. If specified, this will be used to populate
	 *     the Content-Type header.
	 *
	 * Note that the etag is optional, but it's highly encouraged to return for
	 * speed reasons.
	 *
	 * The calendardata is also optional. If it's not returned
	 * 'getCalendarObject' will be called later, which *is* expected to return
	 * calendardata.
	 *
	 * If neither etag or size are specified, the calendardata will be
	 * used/fetched to determine these numbers. If both are specified the
	 * amount of times this is needed is reduced by a great degree.
	 *
	 * @param string $calendarId
	 *
	 * @return array
	 */
	public function getCalendarObjects($calendarId) {
		$result = $this->gDavBackend->GetObjects($calendarId, static::FILE_EXTENSION, ['types' => static::MESSAGE_CLASSES]);
		$this->logger->trace("calendarId: %s found %d objects", $calendarId, count($result));

		return $result;
	}

	/**
	 * Performs a calendar-query on the contents of this calendar.
	 *
	 * The calendar-query is defined in RFC4791 : CalDAV. Using the
	 * calendar-query it is possible for a client to request a specific set of
	 * object, based on contents of iCalendar properties, date-ranges and
	 * iCalendar component types (VTODO, VEVENT).
	 *
	 * This method should just return a list of (relative) urls that match this
	 * query.
	 *
	 * The list of filters are specified as an array. The exact array is
	 * documented by \Sabre\CalDAV\CalendarQueryParser.
	 *
	 * Note that it is extremely likely that getCalendarObject for every path
	 * returned from this method will be called almost immediately after. You
	 * may want to anticipate this to speed up these requests.
	 *
	 * This method provides a default implementation, which parses *all* the
	 * iCalendar objects in the specified calendar.
	 *
	 * This default may well be good enough for personal use, and calendars
	 * that aren't very large. But if you anticipate high usage, big calendars
	 * or high loads, you are strongly advised to optimize certain paths.
	 *
	 * The best way to do so is override this method and to optimize
	 * specifically for 'common filters'.
	 *
	 * Requests that are extremely common are:
	 *   * requests for just VEVENTS
	 *   * requests for just VTODO
	 *   * requests with a time-range-filter on either VEVENT or VTODO.
	 *
	 * ..and combinations of these requests. It may not be worth it to try to
	 * handle every possible situation and just rely on the (relatively
	 * easy to use) CalendarQueryValidator to handle the rest.
	 *
	 * Note that especially time-range-filters may be difficult to parse. A
	 * time-range filter specified on a VEVENT must for instance also handle
	 * recurrence rules correctly.
	 * A good example of how to interpret all these filters can also simply
	 * be found in \Sabre\CalDAV\CalendarQueryFilter. This class is as correct
	 * as possible, so it gives you a good idea on what type of stuff you need
	 * to think of.
	 *
	 * @param mixed $calendarId
	 * @param array $filters    see \Sabre\CalDAV\CalendarQueryParser
	 *
	 * @return array
	 */
	public function calendarQuery($calendarId, array $filters) {
		$start = $end = null;
		$types = [];
		// Message classes and the time range of events are filtered by
		// MAPI, everything else by validating each candidate object.
		$requirePostFilter = !empty($filters['prop-filters']) || count($filters['comp-filters'] ?? []) > 1;
		foreach ($filters['comp-filters'] ?? [] as $filter) {
			if (!empty($filter['is-not-defined'])) {
				// objects without the component, of any class
				$types = static::MESSAGE_CLASSES;
				$requirePostFilter = true;

				break;
			}
			if (!empty($filter['comp-filters']) || !empty($filter['prop-filters'])) {
				$requirePostFilter = true;
			}
			if ($filter['name'] == 'VEVENT') {
				$types[] = 'IPM.Appointment';
				if (is_array($filter['time-range'] ?? null)) {
					if (isset($filter['time-range']['start'])) {
						$start = $filter['time-range']['start']->getTimestamp();
					}
					if (isset($filter['time-range']['end'])) {
						$end = $filter['time-range']['end']->getTimestamp();
					}
				}
			}
			elseif ($filter['name'] == 'VTODO' || $filter['name'] == 'VJOURNAL') {
				$types[] = $filter['name'] == 'VTODO' ? 'IPM.Task' : Notes::MESSAGE_CLASS;
				// only events are restricted by time
				if (is_array($filter['time-range'] ?? null)) {
					$requirePostFilter = true;
				}
			}
			else {
				$requirePostFilter = true;
			}
		}

		$objfilters = [];
		// RFC 4791: both bounds of the time-range are optional, substitute the missing one
		if ($start !== null || $end !== null) {
			$objfilters["start"] = $start ?? 0;
			$objfilters["end"] = $end ?? 253402300799; // 9999-12-31T23:59:59Z
		}
		if (!empty($types)) {
			$objfilters["types"] = $types;
		}

		// a search by UID, e.g. by getCalendarObjectByUID(), only needs the objects with this UID
		$uid = static::GetUidOfFilters($filters);
		if ($uid !== null) {
			$objects = $this->gDavBackend->GetObjects($calendarId, static::FILE_EXTENSION, $objfilters + ['uid' => $uid]);
			if (empty($objects)) {
				// goids not derived from the UID, see GetMapiMessageForId()
				$objects = array_filter(
					$this->gDavBackend->GetObjects($calendarId, static::FILE_EXTENSION, $objfilters),
					fn ($object) => rawurldecode($object['id']) === $uid
				);
			}
		}
		else {
			$objects = $this->gDavBackend->GetObjects($calendarId, static::FILE_EXTENSION, $objfilters);
		}
		$result = [];
		foreach ($objects as $object) {
			// objects found by UID need no further check: the filters only ask for the UID, and a
			// UID derived from the goid of an Outlook item may differ in case from the searched one
			if ($requirePostFilter && !$this->matchesFilters($calendarId, $object, $filters, $uid === null)) {
				continue;
			}
			$result[] = $object['uri'];
		}

		return array_values(array_unique($result));
	}

	/**
	 * Returns the UID searched by the filters of a calendar-query, if
	 * that is all they do.
	 *
	 * RFC 4791 text-match finds substrings, a search by UID is taken for
	 * the exact UID as by Sabre's own backends.
	 *
	 * @return null|string
	 */
	public static function GetUidOfFilters(array $filters) {
		if (!empty($filters['is-not-defined']) || !empty($filters['prop-filters']) || !empty($filters['time-range']) || count($filters['comp-filters'] ?? []) !== 1) {
			return null;
		}
		$compFilter = $filters['comp-filters'][0];
		if (!empty($compFilter['is-not-defined']) || !empty($compFilter['comp-filters']) || !empty($compFilter['time-range']) || count($compFilter['prop-filters'] ?? []) !== 1) {
			return null;
		}
		$propFilter = $compFilter['prop-filters'][0];
		$textMatch = $propFilter['text-match'] ?? null;
		if (strtoupper($propFilter['name'] ?? '') !== 'UID' || !empty($propFilter['is-not-defined']) || !empty($propFilter['param-filters']) ||
			!empty($propFilter['time-range']) || !is_array($textMatch) || !empty($textMatch['negate-condition']) ||
			!in_array($textMatch['collation'] ?? 'i;ascii-casemap', ['i;octet', 'i;ascii-casemap'], true) || ($textMatch['value'] ?? '') === '') {
			return null;
		}

		return (string) $textMatch['value'];
	}

	/**
	 * Checks an object against the filters of a calendar-query. A matching
	 * object is kept for getCalendarObject().
	 *
	 * @param string $calendarId
	 * @param array  $object     as returned by GetObjects()
	 * @param bool   $validate   false if the object is known to match
	 *
	 * @return bool
	 */
	private function matchesFilters($calendarId, array $object, array $filters, $validate = true) {
		$data = $this->getQueryCandidate($calendarId, $object);
		if ($data === null || $data['calendardata'] === '') {
			return false;
		}

		try {
			$matches = !$validate || $this->validateFilterForObject($data, $filters);
		}
		catch (\Throwable $throwable) {
			$this->logger->debug("Unable to check object %s against the filters: %s", $object['uri'], $throwable->getMessage());

			return false;
		}
		if ($matches) {
			// Sabre fetches the objects found right after
			$this->queryObjects[$calendarId][$object['uri']] = $data;
		}

		return $matches;
	}

	/**
	 * Returns an object found by GetObjects() with its calendar data.
	 *
	 * @param string $calendarId
	 * @param array  $object     as returned by GetObjects()
	 *
	 * @return null|array
	 */
	protected function getQueryCandidate($calendarId, array $object) {
		$mapimessage = mapi_msgstore_openentry($this->gDavBackend->GetStoreById($calendarId), hex2bin($object['entryid']));
		if (!$mapimessage) {
			$this->logger->info("Unable to open object %s: 0x%x", $object['uri'], mapi_last_hresult());

			return null;
		}

		return $this->toCalendarObject($calendarId, $mapimessage, $object['id']);
	}

	/**
	 * Returns information from a single calendar object, based on its object uri.
	 *
	 * The object uri is only the basename, or filename and not a full path.
	 *
	 * The returned array must have the same keys as getCalendarObjects. The
	 * 'calendardata' object is required here though, while it's not required
	 * for getCalendarObjects.
	 *
	 * This method must return null if the object did not exist.
	 *
	 * @param string   $calendarId
	 * @param string   $objectUri
	 * @param resource $mapifolder optional mapifolder resource, used if available
	 *
	 * @return null|array
	 */
	public function getCalendarObject($calendarId, $objectUri, $mapifolder = null) {
		$this->logger->trace("calendarId: %s - objectUri: %s - mapifolder: %s", $calendarId, $objectUri, $mapifolder);

		if (isset($this->queryObjects[$calendarId][$objectUri])) {
			return $this->queryObjects[$calendarId][$objectUri];
		}

		if (!$mapifolder) {
			$mapifolder = $this->gDavBackend->GetMapiFolder($calendarId);
		}

		$mapimessage = $this->gDavBackend->GetMapiMessageForId($calendarId, $objectUri, $mapifolder, static::FILE_EXTENSION);
		if (!$mapimessage) {
			$this->logger->info("Object NOT FOUND");

			return null;
		}

		return $this->toCalendarObject($calendarId, $mapimessage);
	}

	/**
	 * Returns a message as calendar object.
	 *
	 * @param string      $calendarId
	 * @param mixed       $mapimessage
	 * @param null|string $realId      the id of the object, if known
	 *
	 * @return array
	 */
	private function toCalendarObject($calendarId, $mapimessage, $realId = null) {
		$realId ??= $this->gDavBackend->GetIdOfMapiMessage($calendarId, $mapimessage);

		// this should be cached or moved to gDavBackend
		$session = $this->gDavBackend->GetSession();
		$ab = $this->gDavBackend->GetAddressBook();

		$classProps = mapi_getprops($mapimessage, [PR_MESSAGE_CLASS]);
		if (Notes::IsNoteClass($classProps[PR_MESSAGE_CLASS] ?? '')) {
			$ics = $this->notes->ToICal($calendarId, $mapimessage, rawurldecode($realId));
		}
		else {
			$ics = mapi_mapitoical($session, $ab, $mapimessage, []);
		}
		if (!$ics && mapi_last_hresult()) {
			$err = mapi_last_hresult();
			$this->logger->error("Error generating iCal: %s (0x%x)",
				mapi_strerror($err), $err);
			$ics = null;
		}
		elseif (!$ics) {
			$this->logger->error("Error generating iCal: unknown error");
			$ics = null;
		}

		$properties = $this->gDavBackend->GetCustomProperties($calendarId);
		$props = mapi_getprops($mapimessage, [PR_LAST_MODIFICATION_TIME, PR_SENSITIVITY, $properties['private']]);
		$hidden = $this->gDavBackend->IsPrivateHidden($calendarId, $props);
		if ($ics !== null && $hidden) {
			$ics = $this->maskPrivateData($ics);
		}

		$r = [
			'id' => $realId,
			'uri' => $realId . static::FILE_EXTENSION,
			'etag' => '"' . $props[PR_LAST_MODIFICATION_TIME] . ($hidden ? '-p' : '') . '"',
			'lastmodified' => $props[PR_LAST_MODIFICATION_TIME],
			'calendarid' => $calendarId,
			'size' => ($ics !== null ? strlen($ics) : 0),
			'calendardata' => ($ics !== null ? $ics : ''),
		];
		$this->logger->trace("returned data id: %s - size: %d - etag: %s", $r['id'], $r['size'], $r['etag']);

		return $r;
	}

	/**
	 * Creates a new calendar object.
	 *
	 * The object uri is only the basename, or filename and not a full path.
	 *
	 * It is possible return an etag from this function, which will be used in
	 * the response to this PUT request. Note that the ETag must be surrounded
	 * by double-quotes.
	 *
	 * However, you should only really return this ETag if you don't mangle the
	 * calendar-data. If the result of a subsequent GET to this object is not
	 * the exact same as this request body, you should omit the ETag.
	 *
	 * @param mixed  $calendarId
	 * @param string $objectUri
	 * @param string $calendarData
	 *
	 * @return null|string
	 */
	public function createCalendarObject($calendarId, $objectUri, $calendarData) {
		$this->logger->trace("calendarId: %s - objectUri: %s", $calendarId, $objectUri);
		unset($this->queryObjects[$calendarId]);
		$objectId = $this->gDavBackend->GetObjectIdFromObjectUri($objectUri, static::FILE_EXTENSION);
		$folder = $this->gDavBackend->GetMapiFolder($calendarId);
		$mapimessage = $this->gDavBackend->CreateObject($calendarId, $folder, $objectId);
		$retval = $this->setData($calendarId, $mapimessage, $calendarData);
		if (!$retval) {
			return null;
		}

		return '"' . $retval . '"';
	}

	/**
	 * Updates an existing calendarobject, based on its uri.
	 *
	 * The object uri is only the basename, or filename and not a full path.
	 *
	 * It is possible return an etag from this function, which will be used in
	 * the response to this PUT request. Note that the ETag must be surrounded
	 * by double-quotes.
	 *
	 * However, you should only really return this ETag if you don't mangle the
	 * calendar-data. If the result of a subsequent GET to this object is not
	 * the exact same as this request body, you should omit the ETag.
	 *
	 * @param mixed  $calendarId
	 * @param string $objectUri
	 * @param string $calendarData
	 *
	 * @return null|string
	 */
	public function updateCalendarObject($calendarId, $objectUri, $calendarData) {
		$this->logger->trace("calendarId: %s - objectUri: %s", $calendarId, $objectUri);
		unset($this->queryObjects[$calendarId]);

		$folder = $this->gDavBackend->GetMapiFolder($calendarId);
		$mapimessage = $this->gDavBackend->GetMapiMessageForId($calendarId, $objectUri, null, static::FILE_EXTENSION);
		// the client only knows the masked data
		$properties = $this->gDavBackend->GetCustomProperties($calendarId);
		if ($this->gDavBackend->IsPrivateHidden($calendarId, mapi_getprops($mapimessage, [PR_SENSITIVITY, $properties['private']]))) {
			throw new Forbidden('Permission denied to modify the private object');
		}
		$retval = $this->setData($calendarId, $mapimessage, $calendarData);
		if (!$retval) {
			return null;
		}

		return '"' . $retval . '"';
	}

	/**
	 * Sets data for a calendar item.
	 *
	 * @param mixed  $calendarId
	 * @param mixed  $mapimessage
	 * @param string $ics
	 *
	 * @return null|string
	 */
	private function setData($calendarId, $mapimessage, $ics) {
		// this should be cached or moved to gDavBackend
		$store = $this->gDavBackend->GetStoreById($calendarId);
		$session = $this->gDavBackend->GetSession();
		$ab = $this->gDavBackend->GetAddressBook();

		$trimmed = static::TrimTimezoneObservances($ics);
		if ($trimmed !== $ics) {
			$ics = $trimmed;
			$this->logger->trace("newics: %s", $ics);
		}

		if (stripos($ics, 'BEGIN:VJOURNAL') !== false && $this->isNotesFolder($calendarId)) {
			try {
				$vcalendar = Reader::read($ics);
			}
			catch (\Throwable $throwable) {
				throw new UnsupportedMediaType('Unable to convert the calendar data');
			}
			if (!$this->notes->FromICal($calendarId, $mapimessage, $vcalendar)) {
				throw new UnsupportedMediaType('Unable to convert the calendar data');
			}
			if (!mapi_savechanges($mapimessage)) {
				$this->gDavBackend->ThrowMapiError('Error saving mapi object');
			}
			$props = mapi_getprops($mapimessage, [PR_LAST_MODIFICATION_TIME]);

			return $props[PR_LAST_MODIFICATION_TIME];
		}

		if (!mapi_icaltomapi($session, $store, $ab, $mapimessage, $ics, false)) {
			// gromox fails with MAPI_E_CALL_FAILED on data it cannot convert
			if (mapi_last_hresult() == MAPI_E_CALL_FAILED) {
				$this->logger->error("Unable to convert the calendar data");

				throw new UnsupportedMediaType('Unable to convert the calendar data');
			}
			$this->gDavBackend->ThrowMapiError('Error updating mapi object');
		}

		// CLASS only sets PR_SENSITIVITY, grommunio-web shows the private flag from PidLidPrivate
		$properties = $this->gDavBackend->GetCustomProperties($calendarId);
		$props = mapi_getprops($mapimessage, [PR_SENSITIVITY]);
		mapi_setprops($mapimessage, [$properties['private'] => ($props[PR_SENSITIVITY] ?? SENSITIVITY_NONE) >= SENSITIVITY_PRIVATE]);

		if (stripos($ics, 'BEGIN:VTODO') !== false) {
			$this->applyVtodoSpecificProperties($store, $mapimessage, $ics);
		}

		// Set default properties only for VEVENTs. VTODOs use different property sets.
		if (stripos($ics, 'BEGIN:VEVENT') !== false) {
			$propList = MapiProps::GetAppointmentProperties();
			$defaultProps = MapiProps::GetDefaultAppointmentProperties();
			$propsToSet = $this->gDavBackend->GetPropsToSet($calendarId, $mapimessage, $propList, $defaultProps);
			if (!empty($propsToSet)) {
				mapi_setprops($mapimessage, $propsToSet);
			}
		}

		if (!mapi_savechanges($mapimessage)) {
			$this->gDavBackend->ThrowMapiError('Error saving mapi object');
		}
		$props = mapi_getprops($mapimessage, [PR_LAST_MODIFICATION_TIME]);

		return $props[PR_LAST_MODIFICATION_TIME];
	}

	/**
	 * Reduces the time zones sent by Evolution to their current rules.
	 *
	 * Evolution (libical, recognizable by X-LIC-LOCATION) sends all
	 * historic STANDARD and DAYLIGHT observances of a time zone, some of
	 * which are not supported by Outlook/Exchange. Only the latest
	 * observance of each kind is kept in every such VTIMEZONE.
	 *
	 * @see GRAM-52
	 *
	 * @param string $ics
	 *
	 * @return string the data, unchanged if nothing was removed or it cannot be parsed
	 */
	public static function TrimTimezoneObservances($ics) {
		if (stripos($ics, 'X-LIC-LOCATION') === false) {
			return $ics;
		}

		try {
			// like libical, accept e.g. "_" in property names
			$vcalendar = Reader::read($ics, Reader::OPTION_FORGIVING);
		}
		catch (\Throwable $throwable) {
			return $ics;
		}

		$changed = false;
		foreach ($vcalendar->select('VTIMEZONE') as $vtimezone) {
			if (!isset($vtimezone->{'X-LIC-LOCATION'})) {
				continue;
			}
			foreach (['STANDARD', 'DAYLIGHT'] as $kind) {
				$observances = $vtimezone->select($kind);
				if (count($observances) < 2) {
					continue;
				}
				// keep the observance starting last, the later one on equal starts
				$keep = null;
				$keepStart = null;
				foreach ($observances as $observance) {
					$start = isset($observance->DTSTART) ? (string) $observance->DTSTART->getValue() : '';
					if ($keep === null || strcmp($start, $keepStart) >= 0) {
						$keep = $observance;
						$keepStart = $start;
					}
				}
				foreach ($observances as $observance) {
					if ($observance !== $keep) {
						$vtimezone->remove($observance);
						$changed = true;
					}
				}
			}
		}

		return $changed ? $vcalendar->serialize() : $ics;
	}

	/**
	 * Ensures VTODO-specific fields such as start date, due date, priority and estimated duration
	 * are populated even when mapi_icaltomapi leaves them unset.
	 *
	 * @param mixed  $store
	 * @param mixed  $mapimessage
	 * @param string $ics
	 */
	private function applyVtodoSpecificProperties($store, $mapimessage, $ics) {
		try {
			$vcalendar = Reader::read($ics);
		}
		catch (\Throwable $throwable) {
			$this->logger->debug("Unable to parse VTODO data for supplemental property mapping: %s", $throwable->getMessage());

			return;
		}

		$vtodos = $vcalendar->select('VTODO');
		if (empty($vtodos)) {
			return;
		}

		/** @var \Sabre\VObject\Component\VTodo $vtodo */
		$vtodo = reset($vtodos);

		$propMap = getPropIdsFromStrings($store, [
			'taskStart' => 'PT_SYSTIME:PSETID_Task:' . PidLidTaskStartDate,
			'taskDue' => 'PT_SYSTIME:PSETID_Task:' . PidLidTaskDueDate,
			'commonStart' => 'PT_SYSTIME:PSETID_Common:' . PidLidCommonStart,
			'commonEnd' => 'PT_SYSTIME:PSETID_Common:' . PidLidCommonEnd,
			'estimatedEffort' => 'PT_LONG:PSETID_Task:' . PidLidTaskEstimatedEffort,
		]);

		$propsToUpdate = [];

		if (isset($vtodo->DTSTART)) {
			$timestamp = $this->timestampFromVObjectProperty($vtodo->DTSTART);
			if ($timestamp !== null) {
				if (isset($propMap['taskStart'])) {
					$propsToUpdate[$propMap['taskStart']] = $timestamp;
				}
				if (isset($propMap['commonStart'])) {
					$propsToUpdate[$propMap['commonStart']] = $timestamp;
				}
			}
		}

		if (isset($vtodo->DUE)) {
			$timestamp = $this->timestampFromVObjectProperty($vtodo->DUE);
			if ($timestamp !== null) {
				if (isset($propMap['taskDue'])) {
					$propsToUpdate[$propMap['taskDue']] = $timestamp;
				}
				if (isset($propMap['commonEnd'])) {
					$propsToUpdate[$propMap['commonEnd']] = $timestamp;
				}
			}
		}

		if (isset($vtodo->{'ESTIMATED-DURATION'})) {
			$minutes = $this->minutesFromIsoDuration($vtodo->{'ESTIMATED-DURATION'}->getValue());
			if ($minutes !== null && isset($propMap['estimatedEffort'])) {
				$propsToUpdate[$propMap['estimatedEffort']] = $minutes;
			}
		}

		if (isset($vtodo->PRIORITY)) {
			$priorityMapping = $this->mapPriorityValues($vtodo->PRIORITY->getValue());
			if ($priorityMapping !== null) {
				if (isset($priorityMapping['importance'])) {
					$propsToUpdate[PR_IMPORTANCE] = $priorityMapping['importance'];
				}
				if (isset($priorityMapping['priority'])) {
					$propsToUpdate[PR_PRIORITY] = $priorityMapping['priority'];
				}
			}
		}

		if (!empty($propsToUpdate)) {
			mapi_setprops($mapimessage, $propsToUpdate);
		}
	}

	/**
	 * Checks if a calendar is a notes folder.
	 *
	 * @param string $calendarId
	 *
	 * @return bool
	 */
	private function isNotesFolder($calendarId) {
		$props = mapi_getprops($this->gDavBackend->GetMapiFolder($calendarId), [PR_CONTAINER_CLASS]);

		return strcasecmp($props[PR_CONTAINER_CLASS] ?? '', Notes::CONTAINER_CLASS) === 0;
	}

	/**
	 * Reduces private events, tasks and notes to their scheduling data.
	 *
	 * @param string $ics
	 *
	 * @return null|string null if the data cannot be parsed
	 */
	private function maskPrivateData($ics) {
		try {
			$vcalendar = Reader::read($ics);
		}
		catch (\Throwable $throwable) {
			$this->logger->error("Unable to parse private object: %s", $throwable->getMessage());

			return null;
		}

		foreach ($vcalendar->getComponents() as $component) {
			if (!in_array($component->name, ['VEVENT', 'VTODO', 'VJOURNAL'], true)) {
				continue;
			}
			foreach ($component->children() as $child) {
				if (!in_array($child->name, self::MASK_KEEP_PROPERTIES, true)) {
					$component->remove($child);
				}
			}
			$component->SUMMARY = 'Private';
		}

		return $vcalendar->serialize();
	}

	/**
	 * Converts a VObject datetime property into a UTC timestamp suitable for PT_SYSTIME fields.
	 *
	 * @param \Sabre\VObject\Property $property
	 *
	 * @return int|null
	 */
	private function timestampFromVObjectProperty($property) {
		if (!$property instanceof \Sabre\VObject\Property) {
			return null;
		}

		try {
			if ($property instanceof \Sabre\VObject\Property\ICalendar\DateTime) {
				$dateTime = $property->getDateTime(new \DateTimeZone('UTC'));
			}
			else {
				$dateTime = new \DateTimeImmutable((string) $property, new \DateTimeZone('UTC'));
			}
		}
		catch (\Throwable $throwable) {
			$this->logger->debug("Unable to interpret iCalendar date value: %s", $throwable->getMessage());

			return null;
		}

		return (int) $dateTime->getTimestamp();
	}

	/**
	 * Converts an ISO-8601 duration string into minutes as expected by PidLidTaskEstimatedEffort.
	 *
	 * @param string $duration
	 *
	 * @return int|null
	 */
	private function minutesFromIsoDuration($duration) {
		$duration = trim((string) $duration);
		if ($duration === '') {
			return null;
		}

		$rawDuration = $duration;
		$isNegative = false;
		if ($duration[0] === '-') {
			$isNegative = true;
			$duration = substr($duration, 1);
		}

		try {
			$interval = new \DateInterval($duration);
		}
		catch (\Throwable $throwable) {
			$this->logger->debug("Unable to parse ISO duration '%s': %s", $rawDuration, $throwable->getMessage());

			return null;
		}

		$reference = new \DateTimeImmutable('@0');
		$target = $reference->add($interval);
		$seconds = $target->getTimestamp() - $reference->getTimestamp();
		$minutes = (int) round($seconds / 60);

		return $isNegative ? -$minutes : $minutes;
	}

	/**
	 * Maps RFC5545 PRIORITY values onto the MAPI importance/priority fields.
	 *
	 * @param string $priority
	 *
	 * @return array|null
	 */
	private function mapPriorityValues($priority) {
		if ($priority === null || $priority === '') {
			return null;
		}

		$priority = (int) $priority;
		if ($priority >= 1 && $priority <= 4) {
			return ['importance' => IMPORTANCE_HIGH, 'priority' => 1];
		}
		if ($priority === 5) {
			return ['importance' => IMPORTANCE_NORMAL, 'priority' => 0];
		}
		if ($priority >= 6 && $priority <= 9) {
			return ['importance' => IMPORTANCE_LOW, 'priority' => -1];
		}

		return null;
	}

	/**
	 * Deletes an existing calendar object.
	 *
	 * The object uri is only the basename, or filename and not a full path.
	 *
	 * @param string $calendarId
	 * @param string $objectUri
	 */
	public function deleteCalendarObject($calendarId, $objectUri) {
		$this->logger->trace("calendarId: %s - objectUri: %s", $calendarId, $objectUri);
		unset($this->queryObjects[$calendarId]);

		$mapifolder = $this->gDavBackend->GetMapiFolder($calendarId);

		// to delete we need the PR_ENTRYID of the message
		// TODO move this part to GrommunioDavBackend
		$mapimessage = $this->gDavBackend->GetMapiMessageForId($calendarId, $objectUri, $mapifolder, static::FILE_EXTENSION);
		$properties = $this->gDavBackend->GetCustomProperties($calendarId);
		$props = mapi_getprops($mapimessage, [PR_ENTRYID, PR_ACCESS, PR_SENSITIVITY, $properties['private']]);
		// messages the user may not delete are skipped without an error
		if (isset($props[PR_ACCESS]) && !($props[PR_ACCESS] & MAPI_ACCESS_DELETE)) {
			throw new Forbidden('Permission denied to delete the object');
		}
		if ($this->gDavBackend->IsPrivateHidden($calendarId, $props)) {
			throw new Forbidden('Permission denied to delete the private object');
		}
		if (!mapi_folder_deletemessages($mapifolder, [$props[PR_ENTRYID]])) {
			$this->gDavBackend->ThrowMapiError('Error deleting mapi object');
		}
	}

	/**
	 * Return a single scheduling object.
	 *
	 * TODO: Add implementation.
	 *
	 * @param string $principalUri
	 * @param string $objectUri
	 *
	 * @return array
	 */
	public function getSchedulingObject($principalUri, $objectUri) {
		$this->logger->trace("principalUri: %s - objectUri: %s", $principalUri, $objectUri);

		return [];
	}

	/**
	 * Returns scheduling objects for the principal URI.
	 *
	 * TODO: Add implementation.
	 *
	 * @param string $principalUri
	 *
	 * @return array
	 */
	public function getSchedulingObjects($principalUri) {
		$this->logger->trace("principalUri: %s", $principalUri);

		return [];
	}

	/**
	 * Delete scheduling object.
	 *
	 * TODO: Add implementation.
	 *
	 * @param string $principalUri
	 * @param string $objectUri
	 */
	public function deleteSchedulingObject($principalUri, $objectUri) {
		$this->logger->trace("principalUri: %s - objectUri: %s", $principalUri, $objectUri);
	}

	/**
	 * Create a new scheduling object.
	 *
	 * TODO: Add implementation.
	 *
	 * @param string $principalUri
	 * @param string $objectUri
	 * @param string $objectData
	 */
	public function createSchedulingObject($principalUri, $objectUri, $objectData) {
		$this->logger->trace("principalUri: %s - objectUri: %s - objectData: %s", $principalUri, $objectUri, $objectData);
	}

	/**
	 * Return CTAG for scheduling inbox.
	 *
	 * TODO: Add implementation.
	 *
	 * @param string $principalUri
	 *
	 * @return string
	 */
	public function getSchedulingInboxCtag($principalUri) {
		$this->logger->trace("principalUri: %s", $principalUri);

		return "empty";
	}

	/**
	 * The getChanges method returns all the changes that have happened, since
	 * the specified syncToken in the specified calendar.
	 *
	 * This function should return an array, such as the following:
	 *
	 * [
	 *   'syncToken' => 'The current synctoken',
	 *   'added'   => [
	 *      'new.txt',
	 *   ],
	 *   'modified'   => [
	 *      'modified.txt',
	 *   ],
	 *   'deleted' => [
	 *      'foo.php.bak',
	 *      'old.txt'
	 *   ]
	 * );
	 *
	 * The returned syncToken property should reflect the *current* syncToken
	 * of the calendar, as reported in the {http://sabredav.org/ns}sync-token
	 * property This is * needed here too, to ensure the operation is atomic.
	 *
	 * If the $syncToken argument is specified as null, this is an initial
	 * sync, and all members should be reported.
	 *
	 * The modified property is an array of nodenames that have changed since
	 * the last token.
	 *
	 * The deleted property is an array with nodenames, that have been deleted
	 * from collection.
	 *
	 * The $syncLevel argument is basically the 'depth' of the report. If it's
	 * 1, you only have to report changes that happened only directly in
	 * immediate descendants. If it's 2, it should also include changes from
	 * the nodes below the child collections. (grandchildren)
	 *
	 * The $limit argument allows a client to specify how many results should
	 * be returned at most. If the limit is not specified, it should be treated
	 * as infinite.
	 *
	 * If the limit (infinite or not) is higher than you're willing to return,
	 * you should throw a Sabre\DAV\Exception\TooMuchMatches() exception.
	 *
	 * If the syncToken is expired (due to data cleanup) or unknown, you must
	 * return null.
	 *
	 * The limit is 'suggestive'. You are free to ignore it.
	 *
	 * @param string $calendarId
	 * @param string $syncToken
	 * @param int    $syncLevel
	 * @param int    $limit
	 *
	 * @return array
	 */
	public function getChangesForCalendar($calendarId, $syncToken, $syncLevel, $limit = null) {
		$this->logger->trace("calendarId: %s - syncToken: %s - syncLevel: %d - limit: %d", $calendarId, $syncToken, $syncLevel, $limit);

		return $this->gDavBackend->Sync($calendarId, $syncToken, static::FILE_EXTENSION, $limit, ['types' => static::MESSAGE_CLASSES]);
	}
}
