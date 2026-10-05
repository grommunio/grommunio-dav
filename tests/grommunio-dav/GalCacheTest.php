<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2026 grommunio GmbH
 *
 * Tests for the sync tokens of the GAL cache.
 */

namespace grommunio\DAV;

use PHPUnit\Framework\TestCase;

/**
 * @internal
 *
 * @coversNothing
 */
class GalCacheTest extends TestCase {
	private $cache;

	protected function setUp(): void {
		$logger = $this->getMockBuilder(GLogger::class)->disableOriginalConstructor()->getMock();
		$this->cache = new GalCache('sqlite::memory:', 0, $logger);
	}

	private static function entries(array $names, string $suffix = ''): array {
		$entries = [];
		foreach ($names as $eid => $name) {
			$entries[] = ['entryid_hex' => $eid, 'uri' => $name . '.vcf', 'vcard_data' => 'card ' . $name . $suffix];
		}

		return $entries;
	}

	public function testUnchangedRefreshKeepsToken() {
		$entries = self::entries(['aa' => 'a', 'bb' => 'b']);
		$this->cache->refresh($entries);
		$token = $this->cache->getSyncToken();
		$this->assertNotNull($token);

		$this->cache->refresh($entries);
		$this->cache->refresh($entries);
		$this->assertSame($token, $this->cache->getSyncToken());
		$changes = $this->cache->getChanges($token);
		$this->assertSame($token, $changes['syncToken']);
		$this->assertSame([], array_merge($changes['added'], $changes['modified'], $changes['deleted']));
	}

	public function testChangesAfterUnchangedRefresh() {
		$this->cache->refresh(self::entries(['aa' => 'a']));
		$this->cache->refresh(self::entries(['aa' => 'a']));
		$token = $this->cache->getSyncToken();
		$this->cache->refresh(self::entries(['aa' => 'a', 'bb' => 'b']));

		$changes = $this->cache->getChanges($token);
		$this->assertSame(['b.vcf'], $changes['added']);
		$this->assertSame($this->cache->getSyncToken(), $changes['syncToken']);
	}

	public function testEmptyListGetsToken() {
		$this->cache->refresh([]);
		$token = $this->cache->getSyncToken();
		$this->assertNotNull($token);

		$this->cache->refresh(self::entries(['aa' => 'a']));
		$this->assertSame(['a.vcf'], $this->cache->getChanges($token)['added']);
	}

	public function testChangesOfSeveralRefreshes() {
		$this->cache->refresh(self::entries(['aa' => 'a', 'bb' => 'b', 'cc' => 'c']));
		$token = $this->cache->getSyncToken();
		$this->cache->refresh(self::entries(['aa' => 'a', 'bb' => 'b', 'dd' => 'd']));
		$this->cache->refresh(self::entries(['aa' => 'a', 'cc' => 'c', 'ee' => 'e']));
		$entries = self::entries(['aa' => 'a', 'cc' => 'c', 'ff' => 'f']);
		$entries[0]['vcard_data'] .= ' changed';
		$this->cache->refresh($entries);

		$changes = $this->cache->getChanges($token);
		// d was added and deleted again, c deleted and added again
		$this->assertSame(['f.vcf'], $changes['added']);
		$this->assertSame(['c.vcf', 'a.vcf'], $changes['modified']);
		$this->assertSame(['b.vcf'], $changes['deleted']);
		$this->assertSame($this->cache->getSyncToken(), $changes['syncToken']);
		$this->assertFalse($changes['result_truncated']);
	}

	public function testLimitAcrossRefreshes() {
		$this->cache->refresh(self::entries(['aa' => 'a']));
		$token = $this->cache->getSyncToken();
		$this->cache->refresh(self::entries(['aa' => 'a', 'bb' => 'b', 'cc' => 'c']));
		$this->cache->refresh(self::entries(['aa' => 'a', 'bb' => 'b', 'cc' => 'c', 'dd' => 'd', 'ee' => 'e']));

		$changes = $this->cache->getChanges($token, 3);
		$this->assertSame(['b.vcf', 'c.vcf'], $changes['added']);
		$this->assertTrue($changes['result_truncated']);
		$this->assertNotSame($this->cache->getSyncToken(), $changes['syncToken']);

		$changes = $this->cache->getChanges($changes['syncToken'], 3);
		$this->assertSame(['d.vcf', 'e.vcf'], $changes['added']);
		$this->assertFalse($changes['result_truncated']);
		$this->assertSame($this->cache->getSyncToken(), $changes['syncToken']);
	}

	public function testOldTokensExpire() {
		$this->cache->refresh(self::entries(['00' => 'x0']));
		$first = $this->cache->getSyncToken();
		$second = null;
		for ($i = 1; $i <= GalCache::KEEP_TOKENS; ++$i) {
			$this->cache->refresh(self::entries(['00' => 'x' . $i]));
			$second ??= $this->cache->getSyncToken();
			// unchanged refreshes keep the token and the history
			$this->cache->refresh(self::entries(['00' => 'x' . $i]));
		}
		$this->assertNull($this->cache->getChanges($first));
		$changes = $this->cache->getChanges($second);
		$this->assertSame(['x' . GalCache::KEEP_TOKENS . '.vcf'], $changes['added']);
		$this->assertSame(['x1.vcf'], $changes['deleted']);
	}

	public function testChangeTypes() {
		$this->cache->refresh(self::entries(['aa' => 'a', 'bb' => 'b', 'cc' => 'c']));
		$token = $this->cache->getSyncToken();
		$entries = self::entries(['aa' => 'a', 'bb' => 'b2', 'dd' => 'd']);
		$entries[0]['vcard_data'] .= ' changed';
		$this->cache->refresh($entries);

		$changes = $this->cache->getChanges($token);
		$this->assertSame(['b2.vcf', 'd.vcf'], $changes['added']);
		$this->assertSame(['a.vcf'], $changes['modified']);
		$this->assertSame(['b.vcf', 'c.vcf'], $changes['deleted']);
		$this->assertFalse($changes['result_truncated']);
	}

	public function testLimitDoesNotLoseChanges() {
		$this->cache->refresh(self::entries(['aa' => 'a']));
		$token = $this->cache->getSyncToken();
		$this->cache->refresh(self::entries(['aa' => 'a', 'bb' => 'b', 'cc' => 'c', 'dd' => 'd']));

		// the changes of one refresh are reported together
		$changes = $this->cache->getChanges($token, 2);
		$this->assertSame(['b.vcf', 'c.vcf', 'd.vcf'], $changes['added']);
		$this->assertSame($this->cache->getSyncToken(), $changes['syncToken']);
		$this->assertFalse($changes['result_truncated']);

		// an initial sync cannot be continued, all entries are reported
		$changes = $this->cache->getChanges(null, 2);
		$this->assertCount(4, $changes['added']);
		$this->assertFalse($changes['result_truncated']);
	}

	public function testTokensOfOlderCache() {
		$file = tempnam(sys_get_temp_dir(), 'galcache');
		$db = new \PDO('sqlite:' . $file);
		$db->exec("
			CREATE TABLE gdav_gal_meta (key TEXT PRIMARY KEY, value TEXT);
			CREATE TABLE gdav_gal_changes (id INTEGER PRIMARY KEY AUTOINCREMENT, sync_token TEXT NOT NULL, uri TEXT NOT NULL, change_type TEXT NOT NULL);
			INSERT INTO gdav_gal_changes (sync_token, uri, change_type) VALUES ('gal-1', 'a.vcf', 'added'), ('gal-2', 'b.vcf', 'added');
			INSERT INTO gdav_gal_meta (key, value) VALUES ('sync_token', 'gal-2');
			CREATE TABLE gdav_gal_entries (entryid_hex TEXT PRIMARY KEY, uri TEXT UNIQUE NOT NULL, display_name TEXT, email TEXT, vcard_data TEXT NOT NULL, etag TEXT NOT NULL, size INTEGER NOT NULL);
		");
		$insert = $db->prepare("INSERT INTO gdav_gal_entries VALUES (:eid, :uri, '', '', :vcard, :etag, 0)");
		foreach (self::entries(['aa' => 'a', 'bb' => 'b']) as $entry) {
			$insert->execute([':eid' => $entry['entryid_hex'], ':uri' => $entry['uri'], ':vcard' => $entry['vcard_data'], ':etag' => '"' . md5($entry['vcard_data']) . '"']);
		}
		$logger = $this->getMockBuilder(GLogger::class)->disableOriginalConstructor()->getMock();
		$cache = new GalCache('sqlite:' . $file, 0, $logger);
		$cache->refresh(self::entries(['aa' => 'a', 'bb' => 'b', 'cc' => 'c']));
		$this->assertSame(['c.vcf'], $cache->getChanges('gal-2')['added']);
		$this->assertSame(['b.vcf', 'c.vcf'], $cache->getChanges('gal-1')['added']);
		unlink($file);
	}

	public function testUnknownToken() {
		$this->cache->refresh(self::entries(['aa' => 'a']));
		$this->assertNull($this->cache->getChanges('gal-unknown'));
	}
}
