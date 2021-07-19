<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2016 - 2018 Kopano b.v.
 * SPDX-FileCopyrightText: Copyright 2020 grommunio GmbH
 *
 * MAPI Property definitions.
 */

namespace grommunio\DAV;

class MapiProps {
    const PROP_VCARDUID = "PT_UNICODE:PSETID_GROMOX:vcarduid";

    /**
     *
     * Returns appointment specific MAPI properties
     * Origins: Z-Push
     *
     * @access public
     *
     * @return array
     */
    public static function GetAppointmentProperties() {

        return array(
            "sourcekey"             => PR_SOURCE_KEY,
            "representingentryid"   => PR_SENT_REPRESENTING_ENTRYID,
            "representingname"      => PR_SENT_REPRESENTING_NAME,
            "sentrepresentingemail" => PR_SENT_REPRESENTING_EMAIL_ADDRESS,
            "sentrepresentingaddt"  => PR_SENT_REPRESENTING_ADDRTYPE,
            "sentrepresentinsrchk"  => PR_SENT_REPRESENTING_SEARCH_KEY,
            "reminderset"           => "PT_BOOLEAN:PSETID_Common:0x8503",
            "remindertime"          => "PT_LONG:PSETID_Common:0x8501",
            "meetingstatus"         => "PT_LONG:PSETID_Appointment:0x8217",
            "isrecurring"           => "PT_BOOLEAN:PSETID_Appointment:0x8223",
            "recurringstate"        => "PT_BINARY:PSETID_Appointment:0x8216",
            "timezonetag"           => "PT_BINARY:PSETID_Appointment:0x8233",
            "timezonedesc"          => "PT_STRING8:PSETID_Appointment:0x8234",
            "recurrenceend"         => "PT_SYSTIME:PSETID_Appointment:0x8236",
            "responsestatus"        => "PT_LONG:PSETID_Appointment:0x8218",
            "commonstart"           => "PT_SYSTIME:PSETID_Common:0x8516",
            "commonend"             => "PT_SYSTIME:PSETID_Common:0x8517",
            "reminderstart"         => "PT_SYSTIME:PSETID_Common:0x8502",
            "duration"              => "PT_LONG:PSETID_Appointment:0x8213",
            "private"               => "PT_BOOLEAN:PSETID_Common:0x8506",
            "uid"                   => "PT_BINARY:PSETID_Meeting:0x23",
            "sideeffects"           => "PT_LONG:PSETID_Common:0x8510",
            "flagdueby"             => "PT_SYSTIME:PSETID_Common:0x8560",
            "icon"                  => PR_ICON_INDEX,
            "mrwassent"             => "PT_BOOLEAN:PSETID_Appointment:0x8229",
            "endtime"               => "PT_SYSTIME:PSETID_Appointment:0x820e",//this is here for calendar restriction, tnef and ical
            "starttime"             => "PT_SYSTIME:PSETID_Appointment:0x820d",//this is here for calendar restriction, tnef and ical
            "clipstart"             => "PT_SYSTIME:PSETID_Appointment:0x8235", //ical only
            "recurrencetype"        => "PT_LONG:PSETID_Appointment:0x8231",
            "body"                  => PR_BODY,
            "rtfcompressed"         => PR_RTF_COMPRESSED,
            "html"                  => PR_HTML,
            "rtfinsync"             => PR_RTF_IN_SYNC,
        );
    }
}
