<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2026 grommunio GmbH
 *
 * Tests for the filters of calendar-query reports.
 */

namespace grommunio\DAV;

use PHPUnit\Framework\TestCase;

/**
 * @internal
 *
 * @coversNothing
 */
class CalendarQueryTest extends TestCase {
	public const OBJECTS = [
		'meeting.ics' => "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:test\r\nBEGIN:VEVENT\r\nUID:meeting\r\nDTSTAMP:20260101T000000Z\r\nDTSTART:20260105T100000Z\r\nSUMMARY:Meeting\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n",
		'lunch.ics' => "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:test\r\nBEGIN:VEVENT\r\nUID:lunch\r\nDTSTAMP:20260101T000000Z\r\nDTSTART:20260105T120000Z\r\nSUMMARY:Lunch\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n",
		'task.ics' => "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:test\r\nBEGIN:VTODO\r\nUID:task\r\nDTSTAMP:20260101T000000Z\r\nDUE:20260301T120000Z\r\nSUMMARY:Task\r\nEND:VTODO\r\nEND:VCALENDAR\r\n",
	];

	public $fetched = [];
	public $objectFilters = [];
	// UIDs not found by their goid
	public $goidMismatch = [];
	// further objects of a test, by uri
	public $extraObjects = [];
	private $backend;

	protected function setUp(): void {
		$logger = $this->getMockBuilder(GLogger::class)->disableOriginalConstructor()->getMock();
		$gDavBackend = $this->getMockBuilder(GrommunioDavBackend::class)->disableOriginalConstructor()->getMock();
		$gDavBackend->method('GetObjects')->willReturnCallback(function ($id, $ext, $objfilters) {
			$this->objectFilters[] = $objfilters;
			$types = $objfilters['types'] ?? ['IPM.Appointment', 'IPM.Task'];
			$objects = [];
			foreach (self::OBJECTS + $this->extraObjects as $uri => $data) {
				$uid = basename($uri, '.ics');
				if (isset($objfilters['uid']) && ($objfilters['uid'] !== $uid || in_array($uid, $this->goidMismatch, true))) {
					continue;
				}
				if (in_array(strpos($data, 'VTODO') ? 'IPM.Task' : 'IPM.Appointment', $types, true)) {
					$objects[] = ['id' => $uid, 'uri' => $uri, 'calendarid' => $id, 'entryid' => bin2hex($uri)];
				}
			}

			return $objects;
		});
		$this->backend = new class($gDavBackend, $logger, $this) extends GrommunioCalDavBackend {
			private $test;

			public function __construct($gDavBackend, $logger, $test) {
				parent::__construct($gDavBackend, $logger);
				$this->test = $test;
			}

			protected function getQueryCandidate($calendarId, array $object) {
				$this->test->fetched[] = $object['uri'];

				return ['uri' => $object['uri'], 'calendarid' => $calendarId, 'calendardata' => (CalendarQueryTest::OBJECTS + $this->test->extraObjects)[hex2bin($object['entryid'])]];
			}
		};
	}

	private function query(array $filters): array {
		return $this->backend->calendarQuery('principals/user:00', $filters);
	}

	private static function compFilter(string $name, array $propFilters = [], $timeRange = false): array {
		return ['name' => $name, 'is-not-defined' => false, 'comp-filters' => [], 'prop-filters' => $propFilters, 'time-range' => $timeRange];
	}

	private static function filters(array $compFilters): array {
		return ['name' => 'VCALENDAR', 'is-not-defined' => false, 'comp-filters' => $compFilters, 'prop-filters' => [], 'time-range' => false];
	}

	public function testComponentOnlyNeedsNoObjectData() {
		$this->assertSame(['meeting.ics', 'lunch.ics'], $this->query(self::filters([self::compFilter('VEVENT')])));
		$this->assertSame([], $this->fetched);
	}

	public function testTextMatch() {
		$propFilter = ['name' => 'SUMMARY', 'is-not-defined' => false, 'param-filters' => [], 'time-range' => false,
			'text-match' => ['value' => 'lunch', 'negate-condition' => false, 'collation' => 'i;ascii-casemap']];
		$this->assertSame(['lunch.ics'], $this->query(self::filters([self::compFilter('VEVENT', [$propFilter])])));
	}

	private static function uidFilter(string $uid): array {
		return ['name' => 'UID', 'is-not-defined' => false, 'param-filters' => [], 'time-range' => null,
			'text-match' => ['value' => $uid, 'negate-condition' => false, 'collation' => 'i;octet']];
	}

	public function testUidLikeSabre() {
		$this->assertSame(['meeting.ics'], $this->query(self::filters([self::compFilter('VEVENT', [self::uidFilter('meeting')])])));
		// only the object with the UID is converted, Sabre gets it without converting it again
		$this->assertSame(['meeting.ics'], $this->fetched);
		$this->assertSame(['types' => ['IPM.Appointment'], 'uid' => 'meeting'], $this->objectFilters[0]);
		$this->assertSame(CalendarQueryTest::OBJECTS['meeting.ics'], $this->backend->getCalendarObject('principals/user:00', 'meeting.ics')['calendardata']);

		$this->assertSame([], $this->query(self::filters([self::compFilter('VTODO', [self::uidFilter('meeting')])])));
		$this->assertSame([], $this->query(self::filters([self::compFilter('VEVENT', [self::uidFilter('meet')])])));
	}

	public function testUidFoundByGoidInOtherCase() {
		// the goid lookup finds an Outlook item by its hex UID in any case, gromox exports it in upper case
		$uid = '040000008200e00074c5b7101a82e00800000000';
		$this->extraObjects = [$uid . '.ics' => "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:test\r\nBEGIN:VEVENT\r\nUID:" . strtoupper($uid) . "\r\nDTSTAMP:20260101T000000Z\r\nDTSTART:20260105T100000Z\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n"];
		$this->assertSame([$uid . '.ics'], $this->query(self::filters([self::compFilter('VEVENT', [self::uidFilter($uid)])])));
	}

	public function testUidWithoutMatchingGoid() {
		$this->goidMismatch = ['lunch'];
		$this->assertSame(['lunch.ics'], $this->query(self::filters([self::compFilter('VEVENT', [self::uidFilter('lunch')])])));
		$this->assertSame(['lunch.ics'], $this->fetched);
	}

	public function testEventTimeRangeNeedsNoObjectData() {
		$timeRange = ['start' => new \DateTime('20260101T000000Z'), 'end' => new \DateTime('20260201T000000Z')];
		$this->query(self::filters([self::compFilter('VEVENT', [], $timeRange)]));
		$this->assertSame([], $this->fetched);
		$this->assertSame(['start' => 1767225600, 'end' => 1769904000, 'types' => ['IPM.Appointment']], $this->objectFilters[0]);
	}

	public function testComponentFiltersAreAnded() {
		// RFC 4791 9.7.1: all comp-filters of a component must match
		$this->assertSame([], $this->query(self::filters([self::compFilter('VEVENT'), self::compFilter('VTODO')])));
	}

	public function testTaskTimeRange() {
		$timeRange = ['start' => new \DateTime('20260101T000000Z'), 'end' => new \DateTime('20260201T000000Z')];
		$this->assertSame([], $this->query(self::filters([self::compFilter('VTODO', [], $timeRange)])));
		$this->assertSame(['types' => ['IPM.Task']], $this->objectFilters[0]);
	}

	public function testComponentNotDefined() {
		$filter = self::compFilter('VEVENT');
		$filter['is-not-defined'] = true;
		$this->assertSame(['task.ics'], $this->query(self::filters([$filter])));
	}
}
