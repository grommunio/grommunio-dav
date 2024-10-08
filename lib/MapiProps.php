<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2016 - 2018 Kopano b.v.
 * SPDX-FileCopyrightText: Copyright 2020 - 2024 grommunio GmbH
 *
 * MAPI Property definitions.
 */

namespace grommunio\DAV;

class MapiProps {
	public const PROP_VCARDUID = "PT_UNICODE:PSETID_GROMOX:vcarduid";

	/**
	 * Returns appointment specific MAPI properties
	 * Origins: Z-Push.
	 *
	 * @return array
	 */
	public static function GetAppointmentProperties() {
		return [
			"sourcekey" => PR_SOURCE_KEY,
			"representingentryid" => PR_SENT_REPRESENTING_ENTRYID,
			"representingname" => PR_SENT_REPRESENTING_NAME,
			"sentrepresentingemail" => PR_SENT_REPRESENTING_EMAIL_ADDRESS,
			"sentrepresentingaddt" => PR_SENT_REPRESENTING_ADDRTYPE,
			"sentrepresentinsrchk" => PR_SENT_REPRESENTING_SEARCH_KEY,
			"reminderset" => "PT_BOOLEAN:PSETID_Common:" . PidLidReminderSet,
			"remindertime" => "PT_LONG:PSETID_Common:" . PidLidReminderDelta,
			"meetingstatus" => "PT_LONG:PSETID_Appointment:" . PidLidAppointmentStateFlags,
			"isrecurring" => "PT_BOOLEAN:PSETID_Appointment:" . PidLidRecurring,
			"recurringstate" => "PT_BINARY:PSETID_Appointment:" . PidLidAppointmentRecur,
			"timezonetag" => "PT_BINARY:PSETID_Appointment:" . PidLidTimeZoneStruct,
			"timezonedesc" => "PT_STRING8:PSETID_Appointment:" . PidLidTimeZoneDescription,
			"recurrenceend" => "PT_SYSTIME:PSETID_Appointment:" . PidLidClipEnd,
			"responsestatus" => "PT_LONG:PSETID_Appointment:" . PidLidResponseStatus,
			"commonstart" => "PT_SYSTIME:PSETID_Common:" . PidLidCommonStart,
			"commonend" => "PT_SYSTIME:PSETID_Common:" . PidLidCommonEnd,
			"reminderstart" => "PT_SYSTIME:PSETID_Common:" . PidLidReminderTime,
			"duration" => "PT_LONG:PSETID_Appointment:" . PidLidAppointmentDuration,
			"private" => "PT_BOOLEAN:PSETID_Common:" . PidLidPrivate,
			"uid" => "PT_BINARY:PSETID_Meeting:" . PidLidCleanGlobalObjectId,
			"sideeffects" => "PT_LONG:PSETID_Common:" . PidLidSideEffects,
			"flagdueby" => "PT_SYSTIME:PSETID_Common:" . PidLidReminderSignalTime,
			"icon" => PR_ICON_INDEX,
			"mrwassent" => "PT_BOOLEAN:PSETID_Appointment:" . PidLidFInvited,
			"endtime" => "PT_SYSTIME:PSETID_Appointment:" . PidLidAppointmentEndWhole, // this is here for calendar restriction, tnef and ical
			"starttime" => "PT_SYSTIME:PSETID_Appointment:" . PidLidAppointmentStartWhole, // this is here for calendar restriction, tnef and ical
			"clipstart" => "PT_SYSTIME:PSETID_Appointment:" . PidLidClipStart, // ical only
			"recurrencetype" => "PT_LONG:PSETID_Appointment:" . PidLidRecurrenceType,
			"body" => PR_BODY,
			"rtfcompressed" => PR_RTF_COMPRESSED,
			"html" => PR_HTML,
			"rtfinsync" => PR_RTF_IN_SYNC,
		];
	}

	/**
	 * Returns default values for some appointment properties.
	 *
	 * @return array
	 */
	public static function GetDefaultAppoinmentProperties() {
		return [
			"isrecurring" => false,
			"meetingstatus" => olNonMeeting,
			"responsestatus" => olResponseNone,
		];
	}
}
