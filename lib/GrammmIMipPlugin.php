<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2016 - 2018 Kopano b.v.
 * SPDX-FileCopyrightText: Copyright 2020 grammm GmbH
 *
 * Sends meeting invitations.
 */

namespace grammm\DAV;

class GrammmIMipPlugin extends \Sabre\CalDAV\Schedule\IMipPlugin {
    /**
     * Constructor.
     *
     * @param GrammmDavBackend $gDavBackend
     * @param GLogger $glogger
     */
    public function __construct(GrammmDavBackend $gDavBackend, GLogger $glogger) {
        $this->gDavBackend = $gDavBackend;
        $this->logger = $glogger;
    }

    /**
     * Sends out meeting invitation.
     *
     * Using the information in iTipMessage to send out a meeting
     * invitation.
     *
     * @param \Sabre\VObject\ITip\Message $iTipMessage
     * @return void
     */

    public function schedule(\Sabre\VObject\ITip\Message $iTipMessage) {
        $this->logger->trace("method: %s - recipient: %s - significantChange: %d - scheduleStatus: %s - message: %s", $iTipMessage->method, $iTipMessage->recipient, $iTipMessage->significantChange, $iTipMessage->scheduleStatus, $iTipMessage->message->serialize());

        if (!$iTipMessage->significantChange) {
            if (!$iTipMessage->scheduleStatus) {
                $iTipMessage->scheduleStatus = "1.0;We got the message, but it's not significant enough to warrant an email";
            }
            return;
        }

        $recipient = preg_replace('!^mailto:!i', '', $iTipMessage->recipient);
        $session = $this->gDavBackend->GetSession();
        $addrbook = $this->gDavBackend->GetAddressBook();
        $store = $this->gDavBackend->GetStore($this->gDavBackend->GetUser());
        $storeprops = mapi_getprops($store, array(PR_IPM_OUTBOX_ENTRYID, PR_IPM_SENTMAIL_ENTRYID));
        if (!isset($storeprops[PR_IPM_OUTBOX_ENTRYID]) || !isset($storeprops[PR_IPM_SENTMAIL_ENTRYID])) {
            /* handle error */
            $this->logger->error("no outbox found aborting user: %s", $this->gDavBackend->GetUser());
            return;
        }

        /* create message and convert */
        $outbox = mapi_msgstore_openentry($store, $storeprops[PR_IPM_OUTBOX_ENTRYID]);
        $newmessage = mapi_folder_createmessage($outbox);
        mapi_icaltomapi($session, $store, $addrbook, $newmessage, $iTipMessage->message->serialize(), false);
        mapi_setprops($newmessage, array(PR_SENTMAIL_ENTRYID => $storeprops[PR_IPM_SENTMAIL_ENTRYID], PR_DELETE_AFTER_SUBMIT => false));

        /* clean the recipients (needed since mapi_icaltomapi does not take IC2M_NO_ORGANIZER) */
        $recipientTable = mapi_message_getrecipienttable($newmessage);
        $recipientRows = mapi_table_queryallrows($recipientTable, array(PR_SMTP_ADDRESS, PR_ROWID));
        $removeRecipients = array();
        foreach ($recipientRows as $key => $recip) {
            if (!isset($recip[PR_SMTP_ADDRESS])) {
                continue;
            }
            if (strcasecmp($recip[PR_SMTP_ADDRESS], $recipient) != 0) {
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
        $this->logger->info("email sent, recipient: %s", $recipient);
        $iTipMessage->scheduleStatus = '1.1;Scheduling message sent via iMip';
    }
}
