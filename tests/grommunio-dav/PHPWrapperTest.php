<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2026 grommunio GmbH
 *
 * Tests for the ICS importer collecting the changes of a sync.
 */

namespace grommunio\DAV;

use PHPUnit\Framework\TestCase;

/**
 * @internal
 *
 * @coversNothing
 */
class PHPWrapperTest extends TestCase {
	public function testDeletedNamesAreEncodedLikeAddedOnes() {
		$logger = $this->getMockBuilder(GLogger::class)->disableOriginalConstructor()->getMock();
		$syncstate = $this->getMockBuilder(GrommunioSyncStateStore::class)->disableOriginalConstructor()->getMock();
		$syncstate->method('getAppttsref')->willReturnMap([
			['f00d', 'aa01', 'a b@example.com/1'],
			['f00d', 'aa02', null],
		]);
		$wrapper = new PHPWrapper(null, $logger, [], '.ics', $syncstate, 'f00d');
		$wrapper->ImportMessageDeletion(0, [hex2bin('aa01'), hex2bin('aa02')]);

		$this->assertSame(['a%20b%40example.com%2F1.ics', 'aa02.ics'], $wrapper->GetDeleted());
		$this->assertSame(2, $wrapper->Total());
	}
}
