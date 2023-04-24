<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2026 grommunio GmbH
 *
 * Tests for the trimming of time zone observances sent by Evolution.
 */

namespace grommunio\DAV;

use PHPUnit\Framework\TestCase;
use Sabre\VObject\Reader;

/**
 * @internal
 *
 * @coversNothing
 */
class TimezoneTrimTest extends TestCase {
	private const BERLIN = "BEGIN:VTIMEZONE\nTZID:Europe/Berlin\nX-LIC-LOCATION:Europe/Berlin\n" .
		"BEGIN:DAYLIGHT\nTZOFFSETFROM:+0100\nTZOFFSETTO:+0200\nTZNAME:CEST\nDTSTART:19160430T230000\nEND:DAYLIGHT\n" .
		"BEGIN:STANDARD\nTZOFFSETFROM:+0200\nTZOFFSETTO:+0100\nTZNAME:CET\nDTSTART:19161001T010000\nEND:STANDARD\n" .
		"BEGIN:DAYLIGHT\nTZOFFSETFROM:+0100\nTZOFFSETTO:+0200\nTZNAME:CEST\nDTSTART:19810329T020000\nRRULE:FREQ=YEARLY;BYMONTH=3;BYDAY=-1SU\nEND:DAYLIGHT\n" .
		"BEGIN:STANDARD\nTZOFFSETFROM:+0200\nTZOFFSETTO:+0100\nTZNAME:CET\nDTSTART:19961027T030000\nRRULE:FREQ=YEARLY;BYMONTH=10;BYDAY=-1SU\nEND:STANDARD\n" .
		"END:VTIMEZONE\n";
	private const TOKYO = "BEGIN:VTIMEZONE\nTZID:Asia/Tokyo\nX-LIC-LOCATION:Asia/Tokyo\n" .
		"BEGIN:STANDARD\nTZOFFSETFROM:+0900\nTZOFFSETTO:+0900\nTZNAME:JST\nDTSTART:19700101T000000\nEND:STANDARD\n" .
		"END:VTIMEZONE\n";
	private const NEWYORK = "BEGIN:VTIMEZONE\nTZID:America/New_York\nX-LIC-LOCATION:America/New_York\n" .
		"BEGIN:STANDARD\nTZOFFSETFROM:-0400\nTZOFFSETTO:-0500\nTZNAME:EST\nDTSTART:19671029T020000\nEND:STANDARD\n" .
		"BEGIN:DAYLIGHT\nTZOFFSETFROM:-0500\nTZOFFSETTO:-0400\nTZNAME:EDT\nDTSTART:20070311T020000\nRRULE:FREQ=YEARLY;BYMONTH=3;BYDAY=2SU\nEND:DAYLIGHT\n" .
		"BEGIN:STANDARD\nTZOFFSETFROM:-0400\nTZOFFSETTO:-0500\nTZNAME:EST\nDTSTART:20071104T020000\nRRULE:FREQ=YEARLY;BYMONTH=11;BYDAY=1SU\nEND:STANDARD\n" .
		"END:VTIMEZONE\n";

	private static function calendar(string $timezones, string $eol = "\r\n"): string {
		$ics = "BEGIN:VCALENDAR\nPRODID:-//Ximian//NONSGML Evolution Calendar//EN\nVERSION:2.0\n" . $timezones .
			"BEGIN:VEVENT\nUID:test-uid\nDTSTAMP:20260101T000000Z\nDTSTART;TZID=Europe/Berlin:20260101T100000\n" .
			"DTEND;TZID=Asia/Tokyo:20260101T200000\nSUMMARY:Test\nEND:VEVENT\nEND:VCALENDAR\n";

		return str_replace("\n", $eol, $ics);
	}

	/**
	 * Returns the DTSTART values of the observances per time zone, sorted.
	 */
	private static function observances(string $ics): array {
		$result = [];
		foreach (Reader::read($ics, Reader::OPTION_FORGIVING)->select('VTIMEZONE') as $vtimezone) {
			$tzid = (string) $vtimezone->TZID;
			$result[$tzid] = [];
			foreach ($vtimezone->children() as $child) {
				if ($child->name === 'STANDARD' || $child->name === 'DAYLIGHT') {
					$result[$tzid][] = $child->name . ' ' . $child->DTSTART;
				}
			}
			sort($result[$tzid]);
		}

		return $result;
	}

	public function testOnlyStandardIsUnchanged() {
		$ics = self::calendar(self::TOKYO);
		$this->assertSame($ics, GrommunioCalDavBackend::TrimTimezoneObservances($ics));
	}

	public function testWithoutXLicLocationIsUnchanged() {
		$ics = self::calendar(str_replace("X-LIC-LOCATION:Europe/Berlin\n", '', self::BERLIN));
		$this->assertSame($ics, GrommunioCalDavBackend::TrimTimezoneObservances($ics));
	}

	public function testUnparsableIsUnchanged() {
		$ics = "BEGIN:VCALENDAR\r\nX-LIC-LOCATION:x\r\nBEGIN:STANDARD\r\n";
		$this->assertSame($ics, GrommunioCalDavBackend::TrimTimezoneObservances($ics));
	}

	/**
	 * @dataProvider eolProvider
	 */
	public function testKeepsLatestObservances(string $eol) {
		$trimmed = GrommunioCalDavBackend::TrimTimezoneObservances(self::calendar(self::BERLIN, $eol));
		$this->assertStringStartsWith("BEGIN:VCALENDAR\r\n", $trimmed);
		$this->assertSame(['Europe/Berlin' => ['DAYLIGHT 19810329T020000', 'STANDARD 19961027T030000']], self::observances($trimmed));
		$this->assertCount(1, Reader::read($trimmed)->select('VEVENT'));
	}

	/**
	 * @dataProvider eolProvider
	 */
	public function testSeveralTimezones(string $eol) {
		$trimmed = GrommunioCalDavBackend::TrimTimezoneObservances(self::calendar(self::BERLIN . self::TOKYO . self::NEWYORK, $eol));
		$this->assertSame([
			'Europe/Berlin' => ['DAYLIGHT 19810329T020000', 'STANDARD 19961027T030000'],
			'Asia/Tokyo' => ['STANDARD 19700101T000000'],
			'America/New_York' => ['DAYLIGHT 20070311T020000', 'STANDARD 20071104T020000'],
		], self::observances($trimmed));
		$vevent = Reader::read($trimmed)->VEVENT;
		$this->assertSame('test-uid', (string) $vevent->UID);
		$this->assertSame('Test', (string) $vevent->SUMMARY);
	}

	public static function eolProvider(): array {
		return [
			'CRLF' => ["\r\n"],
			'LF' => ["\n"],
		];
	}

	public function testSlightlyMalformedIsTrimmed() {
		$ics = str_replace("SUMMARY:Test\n", "SUMMARY:Test\nX-EVOLUTION_CALDAV-ETAG:1\n", self::calendar(self::BERLIN, "\n"));
		$trimmed = GrommunioCalDavBackend::TrimTimezoneObservances($ics);
		$this->assertSame(['Europe/Berlin' => ['DAYLIGHT 19810329T020000', 'STANDARD 19961027T030000']], self::observances($trimmed));
		$this->assertStringContainsString("X-EVOLUTION_CALDAV-ETAG:1\r\n", $trimmed);
	}
}
