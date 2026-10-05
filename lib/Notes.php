<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2026 grommunio GmbH
 *
 * Converts sticky notes from and to VJOURNAL.
 */

namespace grommunio\DAV;

use Sabre\VObject\Component\VCalendar;

class Notes {
	public const MESSAGE_CLASS = 'IPM.StickyNote';
	public const CONTAINER_CLASS = 'IPF.StickyNote';

	// yellow, as grommunio-web and Outlook create them
	private const COLOR = 3;
	private const ICON_INDEX = 0x00000303;
	// grommunio-web cuts the subject after 28 characters
	private const SUBJECT_LENGTH = 28;

	private $gDavBackend;
	private $properties = [];

	/**
	 * Constructor.
	 */
	public function __construct(GrommunioDavBackend $gDavBackend) {
		$this->gDavBackend = $gDavBackend;
	}

	/**
	 * Checks if the message class is a sticky note.
	 *
	 * @param string $messageClass
	 *
	 * @return bool
	 */
	public static function IsNoteClass($messageClass) {
		return strcasecmp((string) $messageClass, self::MESSAGE_CLASS) === 0 ||
			stripos((string) $messageClass, self::MESSAGE_CLASS . '.') === 0;
	}

	/**
	 * Converts a sticky note to a VJOURNAL.
	 *
	 * The first line of the note becomes the SUMMARY, the rest the DESCRIPTION.
	 *
	 * @param string $calendarId
	 * @param mixed  $mapimessage
	 * @param string $uid
	 *
	 * @return string
	 */
	public function ToICal($calendarId, $mapimessage, $uid) {
		$properties = $this->getProperties($calendarId);
		$props = mapi_getprops($mapimessage, [PR_SUBJECT, PR_BODY, PR_SENSITIVITY, PR_CREATION_TIME, PR_LAST_MODIFICATION_TIME, $properties['categories']]);

		$body = str_replace("\r\n", "\n", $props[PR_BODY] ?? '');
		$lines = explode("\n", $body, 2);
		$summary = $body !== '' ? $lines[0] : ($props[PR_SUBJECT] ?? '');

		$vcalendar = new VCalendar([
			'PRODID' => '-//grommunio//grommunio-dav//EN',
		]);
		$vjournal = $vcalendar->add('VJOURNAL', [
			'UID' => $uid,
			'SUMMARY' => $summary,
		]);
		// VCalendar adds DTSTAMP/UID itself, keep them stable
		$modified = $props[PR_LAST_MODIFICATION_TIME] ?? time();
		$vjournal->DTSTAMP = gmdate('Ymd\THis\Z', $modified);
		$vjournal->add('LAST-MODIFIED', gmdate('Ymd\THis\Z', $modified));
		if (isset($props[PR_CREATION_TIME])) {
			$vjournal->add('CREATED', gmdate('Ymd\THis\Z', $props[PR_CREATION_TIME]));
		}
		if (isset($lines[1]) && $lines[1] !== '') {
			$vjournal->add('DESCRIPTION', $lines[1]);
		}
		if (!empty($props[$properties['categories']])) {
			$vjournal->add('CATEGORIES', $props[$properties['categories']]);
		}
		$sensitivity = $props[PR_SENSITIVITY] ?? SENSITIVITY_NONE;
		$vjournal->add('CLASS', $sensitivity === SENSITIVITY_PRIVATE ? 'PRIVATE' :
			($sensitivity === SENSITIVITY_COMPANY_CONFIDENTIAL ? 'CONFIDENTIAL' : 'PUBLIC'));

		return $vcalendar->serialize();
	}

	/**
	 * Sets the properties of a sticky note from a VJOURNAL.
	 *
	 * @param string $calendarId
	 * @param mixed  $mapimessage
	 *
	 * @return bool
	 */
	public function FromICal($calendarId, $mapimessage, VCalendar $vcalendar) {
		$vjournal = $vcalendar->select('VJOURNAL')[0] ?? null;
		if ($vjournal === null) {
			return false;
		}
		$properties = $this->getProperties($calendarId);
		$summary = str_replace("\r\n", "\n", (string) ($vjournal->SUMMARY ?? ''));
		$description = str_replace("\r\n", "\n", (string) ($vjournal->DESCRIPTION ?? ''));
		if ($summary === '' || str_starts_with($description, $summary)) {
			$body = $description;
		}
		elseif ($description === '') {
			$body = $summary;
		}
		else {
			$body = $summary . "\n" . $description;
		}
		$body = str_replace("\n", "\r\n", $body);

		$subject = explode("\r\n", $body)[0];
		if (mb_strlen($subject) > self::SUBJECT_LENGTH) {
			$subject = mb_substr($subject, 0, self::SUBJECT_LENGTH - 3) . '...';
		}

		$class = strtoupper((string) ($vjournal->CLASS ?? 'PUBLIC'));
		$props = [
			PR_MESSAGE_CLASS => self::MESSAGE_CLASS,
			PR_SUBJECT => $subject,
			PR_BODY => $body,
			PR_SENSITIVITY => $class === 'PRIVATE' ? SENSITIVITY_PRIVATE :
				($class === 'CONFIDENTIAL' ? SENSITIVITY_COMPANY_CONFIDENTIAL : SENSITIVITY_NONE),
		];
		$current = mapi_getprops($mapimessage, [$properties['color'], PR_ICON_INDEX]);
		if (!isset($current[$properties['color']])) {
			$props[$properties['color']] = self::COLOR;
			$props[PR_ICON_INDEX] = self::ICON_INDEX;
		}
		$delete = [PR_HTML, PR_RTF_COMPRESSED];
		$categories = [];
		foreach ($vjournal->select('CATEGORIES') as $property) {
			$categories = array_merge($categories, $property->getParts());
		}
		$categories = array_values(array_unique(array_filter($categories)));
		if (!empty($categories)) {
			$props[$properties['categories']] = $categories;
		}
		else {
			$delete[] = $properties['categories'];
		}

		mapi_deleteprops($mapimessage, $delete);

		return mapi_setprops($mapimessage, $props);
	}

	/**
	 * Returns the sticky note properties.
	 *
	 * @param string $calendarId
	 *
	 * @return array
	 */
	private function getProperties($calendarId) {
		$storeId = explode(':', $calendarId)[0];
		if (!isset($this->properties[$storeId])) {
			$this->properties[$storeId] = getPropIdsFromStrings($this->gDavBackend->GetStoreById($calendarId), [
				"color" => "PT_LONG:PSETID_Note:0x8B00",
				"categories" => "PT_MV_STRING8:PS_PUBLIC_STRINGS:Keywords",
			]);
		}

		return $this->properties[$storeId];
	}
}
