<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2026 grommunio GmbH
 *
 * Tests for the URIs of folders.
 */

namespace grommunio\DAV;

use PHPUnit\Framework\TestCase;

/**
 * @internal
 *
 * @coversNothing
 */
class FolderUriTest extends TestCase {
	private static function row(string $sourcekey, string $name): array {
		return [PR_SOURCE_KEY => hex2bin($sourcekey), PR_ENTRYID => 'eid' . $sourcekey, PR_DISPLAY_NAME => $name];
	}

	private static function uris(array $rows, array $defaultIds = []): array {
		$result = [];
		foreach (GrommunioDavBackend::GetFolderUris($rows, $defaultIds) as $sourcekey => $uri) {
			$result[bin2hex($sourcekey)] = $uri;
		}
		ksort($result);

		return $result;
	}

	public function testNameIsUri() {
		$this->assertSame(['01' => 'Calendar', '02' => 'Work'], self::uris([self::row('01', 'Calendar'), self::row('02', 'Work')]));
	}

	public function testUnusableNames() {
		$this->assertSame(['01' => '01', '02' => '02', '03' => '03'], self::uris([self::row('01', 'Work/Private'), self::row('02', ''), self::row('03', '..')]));
	}

	public function testDuplicatesAreDeterministic() {
		$rows = [self::row('03', 'Work'), self::row('02', 'Work'), self::row('01', 'Home')];
		$expected = ['01' => 'Home', '02' => 'Work', '03' => '03'];
		$this->assertSame($expected, self::uris($rows));
		$this->assertSame($expected, self::uris(array_reverse($rows)));
	}

	public function testDefaultFolderKeepsName() {
		$rows = [self::row('01', 'Calendar'), self::row('02', 'Calendar')];
		$this->assertSame(['01' => '01', '02' => 'Calendar'], self::uris($rows, ['eid02']));
		$this->assertSame(['01' => '01', '02' => 'Calendar'], self::uris(array_reverse($rows), ['eid02']));
	}
}
