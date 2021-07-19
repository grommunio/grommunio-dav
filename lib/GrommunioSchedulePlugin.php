<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2016 - 2018 Kopano b.v.
 * SPDX-FileCopyrightText: Copyright 2020 grommunio GmbH
 *
 * Checks Free/Busy information of requested recipients.
 */

namespace grommunio\DAV;

class GrommunioSchedulePlugin extends \Sabre\CalDAV\Schedule\Plugin {
    /**
     * Constructor.
     *
     * @param GrommunioDavBackend $gDavBackend
     * @param GLogger $glogger
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
     * @param \DateTimeInterface $start
     * @param \DateTimeInterface $end
     * @param \Sabre\VObject\Component $request
     * @return array
     */

    protected function getFreeBusyForEmail($email, \DateTimeInterface $start, \DateTimeInterface $end, \Sabre\VObject\Component $request) {
        $this->logger->trace("email: %s - start: %d - end: %d", $email, $start->getTimestamp(), $end->getTimestamp());

        $addrbook = $this->gDavBackend->GetAddressBook();
        $fbsupport = mapi_freebusysupport_open($this->gDavBackend->GetSession());
        $email = preg_replace('!^mailto:!i', '', $email);
        $search = array( array( PR_DISPLAY_NAME => $email ) );
        $userarr = mapi_ab_resolvename($addrbook, $search, EMS_AB_ADDRESS_LOOKUP);
        if (!$userarr) {
            return array(
                'request-status' => '3.7;Could not find principal',
                'href' => 'mailto:' . $email,
            );
        }

        $fbDataArray = mapi_freebusysupport_loaddata($fbsupport, array($userarr[0][PR_ENTRYID]));
        if (!$fbDataArray || !$fbDataArray[0]) {
            return array(
                'calendar-data' => null,
                'request-status' => '2.0;Success',
                'href' => 'mailto:' . $email,
            );
        }

        $enumblock = mapi_freebusydata_enumblocks($fbDataArray[0], $start->getTimestamp(), $end->getTimestamp());
        $result = mapi_freebusyenumblock_ical($addrbook, $enumblock, 100, $start->getTimestamp(), $end->getTimestamp(), $email, $email, "");
        if ($result) {
            $vcalendar = \Sabre\VObject\Reader::read($result, \Sabre\VObject\Reader::OPTION_FORGIVING);
            return array(
                'calendar-data' => $vcalendar,
                'request-status' => '2.0;Success',
                'href' => 'mailto:' . $email,
            );
        }
    }
}
