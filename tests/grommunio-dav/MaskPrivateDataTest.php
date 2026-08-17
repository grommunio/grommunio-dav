<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2026 grommunio GmbH
 *
 * Tests for the masking of private objects.
 */

namespace grommunio\DAV;

use PHPUnit\Framework\TestCase;
use Sabre\VObject\Reader;

/**
 * @internal
 *
 * @coversNothing
 */
class MaskPrivateDataTest extends TestCase {
	private function mask(string $ics): string {
		$logger = $this->getMockBuilder(GLogger::class)->disableOriginalConstructor()->getMock();
		$gDavBackend = $this->getMockBuilder(GrommunioDavBackend::class)->disableOriginalConstructor()->getMock();
		$backend = new GrommunioCalDavBackend($gDavBackend, $logger);
		$method = new \ReflectionMethod($backend, 'maskPrivateData');
		$method->setAccessible(true);

		return $method->invoke($backend, $ics);
	}

	/**
	 * @dataProvider componentProvider
	 */
	public function testMasksComponent(string $name) {
		$ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:test\r\nBEGIN:{$name}\r\nUID:secret-uid\r\nDTSTAMP:20260101T000000Z\r\n" .
			"DTSTART:20260101T100000Z\r\nCLASS:PRIVATE\r\nSUMMARY:Secret title\r\nDESCRIPTION:Secret text\r\n" .
			"LOCATION:Secret place\r\nCATEGORIES:Secret\r\nEND:{$name}\r\nEND:VCALENDAR\r\n";
		$masked = $this->mask($ics);

		$this->assertStringNotContainsString('Secret', $masked);
		$component = Reader::read($masked)->{$name};
		$this->assertSame('Private', (string) $component->SUMMARY);
		$this->assertSame('secret-uid', (string) $component->UID);
		$this->assertSame('20260101T100000Z', (string) $component->DTSTART);
	}

	public static function componentProvider(): array {
		return [['VEVENT'], ['VTODO'], ['VJOURNAL']];
	}
}
