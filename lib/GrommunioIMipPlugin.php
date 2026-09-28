<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2016 - 2018 Kopano b.v.
 * SPDX-FileCopyrightText: Copyright 2020-2024 grommunio GmbH
 *
 * Sends meeting invitations.
 */

namespace grommunio\DAV;

use Sabre\CalDAV\Schedule\IMipPlugin;
use Sabre\VObject\ITip\Message;

class GrommunioIMipPlugin extends IMipPlugin {
	private $logger;
	protected $gDavBackend;
	// attendees already sent a request/cancellation, by uid, sequence and method
	private $sent = [];

	/**
	 * Constructor.
	 */
	public function __construct(GrommunioDavBackend $gDavBackend, GLogger $glogger) {
		$this->gDavBackend = $gDavBackend;
		$this->logger = $glogger;
	}

	/**
	 * Sends out meeting invitation.
	 *
	 * Using the information in iTipMessage to send out a meeting
	 * invitation.
	 */
	public function schedule(Message $iTipMessage) {
		$this->logger->trace("method: %s - recipient: %s - significantChange: %d - scheduleStatus: %s - message: %s", $iTipMessage->method, $iTipMessage->recipient, $iTipMessage->significantChange, $iTipMessage->scheduleStatus, $iTipMessage->message->serialize());

		if (!$iTipMessage->significantChange) {
			if (!$iTipMessage->scheduleStatus) {
				$iTipMessage->scheduleStatus = "1.0;We got the message, but it's not significant enough to warrant an email";
			}

			return;
		}

		$recipient = preg_replace('!^mailto:!i', '', $iTipMessage->recipient);
		// Sabre schedules every attendee on its own. A request or
		// cancellation goes out once to all of them instead, so that
		// everybody sees the other participants.
		$keep = [strtolower($recipient)];
		$sentKey = null;
		if ($iTipMessage->method === 'REQUEST' || $iTipMessage->method === 'CANCEL') {
			$sentKey = $iTipMessage->method . ':' . $iTipMessage->uid . ':' . $iTipMessage->sequence;
			if (isset($this->sent[$sentKey][strtolower($recipient)])) {
				$iTipMessage->scheduleStatus = '1.1;Scheduling message sent via iMip';

				return;
			}
			$keep = array_merge($keep, $this->getScheduledAttendees($iTipMessage, $this->sent[$sentKey] ?? []));
		}
		$session = $this->gDavBackend->GetSession();
		$addrbook = $this->gDavBackend->GetAddressBook();
		$store = $this->gDavBackend->GetStore($this->gDavBackend->GetUser());
		$storeprops = mapi_getprops($store, [PR_IPM_OUTBOX_ENTRYID, PR_IPM_SENTMAIL_ENTRYID]);
		if (!isset($storeprops[PR_IPM_OUTBOX_ENTRYID]) || !isset($storeprops[PR_IPM_SENTMAIL_ENTRYID])) {
			/* handle error */
			$this->logger->error("no outbox found aborting user: %s", $this->gDavBackend->GetUser());

			return;
		}

		/* create message and convert */
		$outbox = mapi_msgstore_openentry($store, $storeprops[PR_IPM_OUTBOX_ENTRYID]);
		$newmessage = mapi_folder_createmessage($outbox);
		mapi_icaltomapi($session, $store, $addrbook, $newmessage, $iTipMessage->message->serialize(), false);
		mapi_setprops($newmessage, [PR_SENTMAIL_ENTRYID => $storeprops[PR_IPM_SENTMAIL_ENTRYID], PR_DELETE_AFTER_SUBMIT => false]);

		/* clean the recipients (needed since mapi_icaltomapi does not take IC2M_NO_ORGANIZER) */
		$recipientTable = mapi_message_getrecipienttable($newmessage);
		$recipientRows = mapi_table_queryallrows($recipientTable, [PR_SMTP_ADDRESS, PR_ROWID]);
		$removeRecipients = [];
		foreach ($recipientRows as $key => $recip) {
			if (!isset($recip[PR_SMTP_ADDRESS])) {
				continue;
			}
			if (!in_array(strtolower($recip[PR_SMTP_ADDRESS]), $keep)) {
				$removeRecipients[] = $recip;
			}
		}
		if (count($removeRecipients) == count($recipientRows)) {
			$this->logger->error("message will have no recipients. List to remove: %s - recipientRows: %s", $removeRecipients, $recipientRows);

			return;
		}
		if (count($removeRecipients) > 0) {
			mapi_message_modifyrecipients($newmessage, MODRECIP_REMOVE, $removeRecipients);
		}

		/* save message and send */
		mapi_savechanges($newmessage);
		mapi_message_submitmessage($newmessage);
		$this->logger->info("email sent, recipients: %s", implode(', ', array_unique($keep)));
		if ($sentKey !== null) {
			foreach ($keep as $address) {
				$this->sent[$sentKey][$address] = true;
			}
		}
		$iTipMessage->scheduleStatus = '1.1;Scheduling message sent via iMip';
	}

	/**
	 * Returns the attendees of a request Sabre sends a message to, without
	 * those already handled.
	 *
	 * @param array $done addresses as keys
	 *
	 * @return array lowercase addresses
	 */
	private function getScheduledAttendees(Message $iTipMessage, array $done) {
		$organizer = strtolower(preg_replace('!^mailto:!i', '', (string) $iTipMessage->sender));
		$addresses = [];
		foreach ($iTipMessage->message->getComponents() as $component) {
			if ($component->name !== 'VEVENT') {
				continue;
			}
			foreach ($component->select('ATTENDEE') as $attendee) {
				$address = strtolower(preg_replace('!^mailto:!i', '', (string) $attendee));
				$agent = strtoupper((string) ($attendee['SCHEDULE-AGENT'] ?? 'SERVER'));
				if ($address === $organizer || $agent !== 'SERVER' || isset($done[$address])) {
					continue;
				}
				$addresses[$address] = true;
			}
		}

		return array_keys($addresses);
	}
}
