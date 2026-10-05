<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2026 grommunio GmbH
 *
 * Tests for PROPPATCH on calendars and address books.
 */

namespace grommunio\DAV;

use PHPUnit\Framework\TestCase;
use Sabre\DAV\PropPatch;

/**
 * @internal
 *
 * @coversNothing
 */
class FolderPropertiesTest extends TestCase {
	private const DAV_PROPS = ['calendarColor' => 0x8001001F, 'calendarOrder' => 0x80020003, 'calendarTransp' => 0x8003000B, 'davUri' => 0x8004001F];

	public $updates = [];

	private function gDavBackend(array $failed) {
		$gDavBackend = $this->getMockBuilder(GrommunioDavBackend::class)->disableOriginalConstructor()->getMock();
		$gDavBackend->method('GetStoreById')->willReturn('store');
		$gDavBackend->method('GetFolderDavProperties')->willReturn(self::DAV_PROPS);
		$gDavBackend->method('GetFolderUri')->willReturn('calendar-uri');
		$gDavBackend->method('UpdateFolderProperties')->willReturnCallback(function ($id, $set, $delete, $uri = null) use ($failed) {
			$this->updates[] = [$set, $delete, $uri];

			return $failed;
		});

		return $gDavBackend;
	}

	private function proppatch($backend, array $mutations): array {
		$propPatch = new PropPatch($mutations);
		if ($backend instanceof GrommunioCalDavBackend) {
			$backend->updateCalendar('principals/user:00', $propPatch);
		}
		else {
			$backend->updateAddressBook('principals/user:00', $propPatch);
		}
		$propPatch->commit();

		return $propPatch->getResult();
	}

	private function calDavBackend(array $failed): GrommunioCalDavBackend {
		$logger = $this->getMockBuilder(GLogger::class)->disableOriginalConstructor()->getMock();

		return new GrommunioCalDavBackend($this->gDavBackend($failed), $logger);
	}

	public function testMetadataApplied() {
		$result = $this->proppatch($this->calDavBackend([]), [
			'{http://apple.com/ns/ical/}calendar-color' => '#FF0000FF',
			'{http://apple.com/ns/ical/}calendar-order' => null,
		]);
		$this->assertSame(['{http://apple.com/ns/ical/}calendar-color' => 200, '{http://apple.com/ns/ical/}calendar-order' => 204], $result);
		$this->assertSame([[self::DAV_PROPS['calendarColor'] => '#FF0000FF'], [self::DAV_PROPS['calendarOrder']], null], $this->updates[0]);
	}

	public function testRefusedRenameOnly() {
		// e.g. a shared calendar: metadata is skipped, the rename refused
		$result = $this->proppatch($this->calDavBackend([PR_DISPLAY_NAME]), [
			'{DAV:}displayname' => 'Holidays',
			'{http://apple.com/ns/ical/}calendar-color' => '#FF0000FF',
		]);
		$this->assertSame(403, $result['{DAV:}displayname']);
		$this->assertSame(200, $result['{http://apple.com/ns/ical/}calendar-color']);
		$this->assertSame('calendar-uri', $this->updates[0][2]);
	}

	public function testEmptyDisplayname() {
		$result = $this->proppatch($this->calDavBackend([]), [
			'{DAV:}displayname' => '',
			'{http://apple.com/ns/ical/}calendar-order' => '2',
		]);
		$this->assertSame(403, $result['{DAV:}displayname']);
		$this->assertSame(200, $result['{http://apple.com/ns/ical/}calendar-order']);
		$this->assertSame([self::DAV_PROPS['calendarOrder'] => 2], $this->updates[0][0]);
	}

	public function testAddressBook() {
		$logger = $this->getMockBuilder(GLogger::class)->disableOriginalConstructor()->getMock();
		$backend = new GrommunioCardDavBackend($this->gDavBackend([PR_DISPLAY_NAME]), $logger);
		$result = $this->proppatch($backend, [
			'{DAV:}displayname' => 'Friends',
			'{urn:ietf:params:xml:ns:carddav}addressbook-description' => 'mine',
		]);
		$this->assertSame(['{DAV:}displayname' => 403, '{urn:ietf:params:xml:ns:carddav}addressbook-description' => 200], $result);
	}
}
