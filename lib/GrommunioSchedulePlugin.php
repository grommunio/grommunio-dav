<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2016 - 2018 Kopano b.v.
 * SPDX-FileCopyrightText: Copyright 2020-2024 grommunio GmbH
 *
 * Checks Free/Busy information of requested recipients.
 */

namespace grommunio\DAV;

use Sabre\CalDAV\Schedule\Plugin;
use Sabre\VObject\Component;
use Sabre\VObject\Reader;

class GrommunioSchedulePlugin extends Plugin {
	private $logger;
	protected $gDavBackend;

	/**
	 * Constructor.
	 */
	public function __construct(GrommunioDavBackend $gDavBackend, GLogger $glogger) {
		$this->gDavBackend = $gDavBackend;
		$this->logger = $glogger;
	}

	/**
	 * Get the Free/Busy information for a recipient.
	 *
	 * Given email, start and end time the function will return
	 * the freebusy blocks.
	 *
	 * @param string $email
	 *
	 * @return array
	 */
	protected function getFreeBusyForEmail($email, \DateTimeInterface $start, \DateTimeInterface $end, Component $request) {
		$this->logger->trace("email: %s - start: %d - end: %d", $email, $start->getTimestamp(), $end->getTimestamp());

		$addrbook = $this->gDavBackend->GetAddressBook();
		$email = preg_replace('!^mailto:!i', '', $email);
		$search = [[PR_DISPLAY_NAME => $email]];
		$userarr = mapi_ab_resolvename($addrbook, $search, EMS_AB_ADDRESS_LOOKUP);
		if (!empty($userarr)) {
			$result = mapi_getuserfreebusyical($this->gDavBackend->GetSession(), $userarr[0][PR_ENTRYID], $start->getTimestamp(), $end->getTimestamp());
			if ($result) {
				$vcalendar = Reader::read($result, Reader::OPTION_FORGIVING);

				return [
					'calendar-data' => $vcalendar,
					'request-status' => '2.0;Success',
					'href' => 'mailto:' . $email,
				];
			}
		}

		return [
			'request-status' => '3.7;Could not find principal',
			'href' => 'mailto:' . $email,
		];
	}
}
