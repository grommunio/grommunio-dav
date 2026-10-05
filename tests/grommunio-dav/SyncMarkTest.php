<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2026 grommunio GmbH
 *
 * Tests for the marks of sync states.
 */

namespace grommunio\DAV;

use PHPUnit\Framework\TestCase;

/**
 * @internal
 *
 * @coversNothing
 */
class SyncMarkTest extends TestCase {
	public function testMark() {
		$this->assertSame('1767225600', GrommunioDavBackend::GetSyncMark(1767225600, 1767225660));
		$this->assertSame('1767225600', GrommunioDavBackend::GetSyncMark(1767225600, 1767229200));
	}

	public function testRecentChangeIsNotMarked() {
		// another change within the same second would not change the mark
		$this->assertSame('', GrommunioDavBackend::GetSyncMark(1767225600, 1767225600));
		$this->assertSame('', GrommunioDavBackend::GetSyncMark(1767225600, 1767225659));
		$this->assertSame('', GrommunioDavBackend::GetSyncMark(1767225600, 1767225500));
	}

	public function testFolderWithoutChanges() {
		$this->assertSame('', GrommunioDavBackend::GetSyncMark(null, 1767225600));
	}
}
