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

	public const FILE_EXTENSION = '.ics';
	// Include both appointments and tasks so task lists sync properly.
	public const MESSAGE_CLASSES = ['IPM.Appointment', 'IPM.Task'];
	public const CONTAINER_CLASS = 'IPF.Appointment';
	public const CONTAINER_CLASSES = ['IPF.Appointment', 'IPF.Task'];

	/**
	 * Constructor.
	 */
	public function __construct(GrommunioDavBackend $gDavBackend, GLogger $glogger) {
		$this->gDavBackend = $gDavBackend;
		$this->logger = $glogger;
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
				elseif (in_array('VEVENT', $components, true)) {
					$containerClass = 'IPF.Appointment';
				}
			}
		}

		// TODO Add displayname
		return $this->gDavBackend->CreateFolder($principalUri, $calendarUri, $containerClass, "");
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
	 *
	 * @return array
	 */
	public function calendarQuery($calendarId, array $filters) {
		$start = $end = null;
		$types = [];
		foreach ($filters['comp-filters'] as $filter) {
			if ($filter['name'] == 'VEVENT') {
				$types[] = 'IPM.Appointment';
			}
			elseif ($filter['name'] == 'VTODO') {
				$types[] = 'IPM.Task';
			}

			/* will this work on tasks? */
			if (is_array($filter['time-range']) && isset($filter['time-range']['start'], $filter['time-range']['end'])) {
				$start = $filter['time-range']['start']->getTimestamp();
				$end = $filter['time-range']['end']->getTimestamp();
			}
		}

		$objfilters = [];
		if ($start != null && $end != null) {
			$objfilters["start"] = $start;
			$objfilters["end"] = $end;
		}
		if (!empty($types)) {
			$objfilters["types"] = $types;
		}

		$objects = $this->gDavBackend->GetObjects($calendarId, static::FILE_EXTENSION, $objfilters);
		$result = [];
		foreach ($objects as $object) {
			$result[] = $object['uri'];
		}

		return $result;
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

		if (!$mapifolder) {
			$mapifolder = $this->gDavBackend->GetMapiFolder($calendarId);
		}

		$mapimessage = $this->gDavBackend->GetMapiMessageForId($calendarId, $objectUri, $mapifolder, static::FILE_EXTENSION);
		if (!$mapimessage) {
			$this->logger->info("Object NOT FOUND");

			return null;
		}

		$realId = $this->gDavBackend->GetIdOfMapiMessage($calendarId, $mapimessage);

		// this should be cached or moved to gDavBackend
		$session = $this->gDavBackend->GetSession();
		$ab = $this->gDavBackend->GetAddressBook();

		$ics = mapi_mapitoical($session, $ab, $mapimessage, []);
		if (!$ics && mapi_last_hresult()) {
			$this->logger->error("Error generating ical, error code: 0x%08X", mapi_last_hresult());
			$ics = null;
		}
		elseif (!$ics) {
			$this->logger->error("Error generating ical, unknown error");
			$ics = null;
		}

		$props = mapi_getprops($mapimessage, [PR_LAST_MODIFICATION_TIME]);

		$r = [
			'id' => $realId,
			'uri' => $realId . static::FILE_EXTENSION,
			'etag' => '"' . $props[PR_LAST_MODIFICATION_TIME] . '"',
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

		$folder = $this->gDavBackend->GetMapiFolder($calendarId);
		$mapimessage = $this->gDavBackend->GetMapiMessageForId($calendarId, $objectUri, null, static::FILE_EXTENSION);
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

		// Evolution sends daylight/standard information in the ical data
		// and some values are not supported by Outlook/Exchange.
		// Strip that data and leave only the last occurrences of
		// daylight/standard information.
		// @see GRAM-52

		$xLicLocation = stripos($ics, 'X-LIC-LOCATION:');
		if (($xLicLocation !== false) &&
				(
					substr_count($ics, 'BEGIN:DAYLIGHT', $xLicLocation) > 0 ||
					substr_count($ics, 'BEGIN:STANDARD', $xLicLocation) > 0
				)) {
			$firstDaytime = stripos($ics, 'BEGIN:DAYLIGHT', $xLicLocation);
			$firstStandard = stripos($ics, 'BEGIN:STANDARD', $xLicLocation);

			$lastDaytime = strripos($ics, 'BEGIN:DAYLIGHT', $xLicLocation);
			$lastStandard = strripos($ics, 'BEGIN:STANDARD', $xLicLocation);

			// the first part of ics until the first piece of standard/daytime information
			$cutStart = $firstDaytime < $firstStandard ? $firstDaytime : $firstStandard;

			if ($lastDaytime > $lastStandard) {
				// the part of the ics with the last piece of standard/daytime information
				$cutEnd = $lastDaytime;

				// the positions of the last piece of standard information
				$cut1 = $lastStandard;
				$cut2 = strripos($ics, 'END:STANDARD', $lastStandard) + 14; // strlen('END:STANDARD')
			}
			else {
				// the part of the ics with the last piece of standard/daytime information
				$cutEnd = $lastStandard;

				// the positions of the last piece of daylight information
				$cut1 = $lastDaytime;
				$cut2 = strripos($ics, 'END:DAYLIGHT', $lastDaytime) + 14; // strlen('END:DAYLIGHT')
			}

			$ics = substr($ics, 0, $cutStart) . substr($ics, $cut1, $cut2 - $cut1) . substr($ics, $cutEnd);
			$this->logger->trace("newics: %s", $ics);
		}

		$ok = mapi_icaltomapi($session, $store, $ab, $mapimessage, $ics, false);
		if (!$ok && mapi_last_hresult()) {
			$this->logger->error("Error updating mapi object, error code: 0x%08X", mapi_last_hresult());

			return null;
		}
		if (!$ok) {
			$this->logger->error("Error updating mapi object, unknown error");

			return null;
		}

		// Set default properties only for VEVENTs. VTODOs use different property sets.
		if (stripos($ics, 'BEGIN:VEVENT') !== false) {
			$propList = MapiProps::GetAppointmentProperties();
			$defaultProps = MapiProps::GetDefaultAppoinmentProperties();
			$propsToSet = $this->gDavBackend->GetPropsToSet($calendarId, $mapimessage, $propList, $defaultProps);
			if (!empty($propsToSet)) {
				mapi_setprops($mapimessage, $propsToSet);
			}
		}

		mapi_savechanges($mapimessage);
		$props = mapi_getprops($mapimessage, [PR_LAST_MODIFICATION_TIME]);

		return $props[PR_LAST_MODIFICATION_TIME];
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

		$mapifolder = $this->gDavBackend->GetMapiFolder($calendarId);

		// to delete we need the PR_ENTRYID of the message
		// TODO move this part to GrommunioDavBackend
		$mapimessage = $this->gDavBackend->GetMapiMessageForId($calendarId, $objectUri, $mapifolder, static::FILE_EXTENSION);
		$props = mapi_getprops($mapimessage, [PR_ENTRYID]);
		mapi_folder_deletemessages($mapifolder, [$props[PR_ENTRYID]]);
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
