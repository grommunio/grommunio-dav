<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2026 grommunio GmbH
 *
 * SQLite cache manager for the Global Address List (GAL).
 *
 * Provides a shared, per-server cache of GAL entries so that every
 * authenticated user reads from the same dataset.  The cache is stored
 * in the SQLite database SYNC_DB.
 */

namespace grommunio\DAV;

class GalCache {
	// changes of this many refreshes are kept for clients to catch up
	public const KEEP_TOKENS = 10;

	private $db;
	private $ttl;
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param string $dbstring PDO connection string (e.g. "sqlite:/path/to/db")
	 * @param int    $ttl      cache lifetime in seconds
	 */
	public function __construct($dbstring, $ttl, GLogger $logger) {
		$this->ttl = $ttl;
		$this->logger = $logger;
		$this->db = new \PDO($dbstring);
		$this->db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
		$this->initSchema();
	}

	/**
	 * Create tables if they don't exist yet.
	 */
	private function initSchema() {
		$hasTokens = $this->db->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'gdav_gal_tokens'")->fetchColumn();
		$this->db->exec("
			CREATE TABLE IF NOT EXISTS gdav_gal_entries (
				entryid_hex  TEXT PRIMARY KEY,
				uri          TEXT UNIQUE NOT NULL,
				display_name TEXT,
				email        TEXT,
				vcard_data   TEXT NOT NULL,
				etag         TEXT NOT NULL,
				size         INTEGER NOT NULL
			);
			CREATE TABLE IF NOT EXISTS gdav_gal_meta (
				key   TEXT PRIMARY KEY,
				value TEXT
			);
			CREATE TABLE IF NOT EXISTS gdav_gal_changes (
				id          INTEGER PRIMARY KEY AUTOINCREMENT,
				sync_token  TEXT NOT NULL,
				uri         TEXT NOT NULL,
				change_type TEXT NOT NULL
			);
			CREATE INDEX IF NOT EXISTS idx_gal_changes_token ON gdav_gal_changes(sync_token);
			CREATE TABLE IF NOT EXISTS gdav_gal_tokens (
				sync_token  TEXT PRIMARY KEY,
				last_change INTEGER NOT NULL
			);
		");
		if (!$hasTokens) {
			// the tokens of caches from before
			$this->db->exec("
				INSERT OR IGNORE INTO gdav_gal_tokens (sync_token, last_change)
					SELECT sync_token, MAX(id) FROM gdav_gal_changes GROUP BY sync_token;
				INSERT OR IGNORE INTO gdav_gal_tokens (sync_token, last_change)
					SELECT value, (SELECT COALESCE(MAX(id), 0) FROM gdav_gal_changes) FROM gdav_gal_meta WHERE key = 'sync_token';
			");
		}
	}

	/**
	 * Check whether the cache needs a refresh (TTL expired or never populated).
	 *
	 * @return bool
	 */
	public function needsRefresh() {
		$stmt = $this->db->prepare("SELECT value FROM gdav_gal_meta WHERE key = 'last_refresh'");
		$stmt->execute();
		$row = $stmt->fetch(\PDO::FETCH_ASSOC);
		if (!$row) {
			return true;
		}

		return (time() - (int) $row['value']) >= $this->ttl;
	}

	/**
	 * Refresh the cache with new GAL entries.
	 *
	 * Compares old vs new entries by entryid_hex and tracks added /
	 * modified / deleted changes for WebDAV-Sync support.  Uses
	 * BEGIN IMMEDIATE to prevent thundering herd on concurrent requests.
	 *
	 * @param array $entries each element: ['entryid_hex', 'uri', 'display_name', 'email', 'vcard_data']
	 */
	public function refresh(array $entries) {
		$this->db->exec("BEGIN IMMEDIATE");

		try {
			// Re-check TTL inside transaction (another request may have refreshed already).
			if (!$this->needsRefresh()) {
				$this->db->exec("COMMIT");
				$this->logger->debug("GalCache: refresh skipped, already refreshed by another request");

				return;
			}

			// Load existing entries keyed by entryid_hex.
			$oldEntries = [];
			$stmt = $this->db->query("SELECT entryid_hex, uri, etag FROM gdav_gal_entries");
			while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
				$oldEntries[$row['entryid_hex']] = $row;
			}

			// Build new map.
			$newMap = [];
			foreach ($entries as $entry) {
				$etag = '"' . md5($entry['vcard_data']) . '"';
				$newMap[$entry['entryid_hex']] = [
					'uri' => $entry['uri'],
					'display_name' => $entry['display_name'] ?? '',
					'email' => $entry['email'] ?? '',
					'vcard_data' => $entry['vcard_data'],
					'etag' => $etag,
					'size' => strlen($entry['vcard_data']),
				];
			}

			// Detect added, modified and deleted entries, by URI.
			$changes = [];
			$addChange = function ($uri, $type) use (&$changes) {
				// a URI deleted and added again is a modification
				$changes[$uri] = isset($changes[$uri]) && $changes[$uri] !== $type ? 'modified' : $type;
			};
			foreach ($newMap as $eid => $data) {
				if (!isset($oldEntries[$eid])) {
					$addChange($data['uri'], 'added');
				}
				elseif ($oldEntries[$eid]['uri'] !== $data['uri']) {
					// the client only knows the card by its old name
					$addChange($oldEntries[$eid]['uri'], 'deleted');
					$addChange($data['uri'], 'added');
				}
				elseif ($oldEntries[$eid]['etag'] !== $data['etag']) {
					$addChange($data['uri'], 'modified');
				}
			}
			foreach ($oldEntries as $eid => $old) {
				if (!isset($newMap[$eid])) {
					$addChange($old['uri'], 'deleted');
				}
			}

			$upsert = $this->db->prepare(
				"REPLACE INTO gdav_gal_entries (entryid_hex, uri, display_name, email, vcard_data, etag, size)
				 VALUES (:eid, :uri, :dn, :email, :vcard, :etag, :size)"
			);
			foreach ($newMap as $eid => $data) {
				$upsert->execute([
					':eid' => $eid,
					':uri' => $data['uri'],
					':dn' => $data['display_name'],
					':email' => $data['email'],
					':vcard' => $data['vcard_data'],
					':etag' => $data['etag'],
					':size' => $data['size'],
				]);
			}
			$deleteStmt = $this->db->prepare("DELETE FROM gdav_gal_entries WHERE entryid_hex = :eid");
			foreach ($oldEntries as $eid => $old) {
				if (!isset($newMap[$eid])) {
					$deleteStmt->execute([':eid' => $eid]);
				}
			}

			$this->db->prepare(
				"REPLACE INTO gdav_gal_meta (key, value) VALUES ('last_refresh', :ts)"
			)->execute([':ts' => time()]);

			// Keep the sync token if nothing changed, clients holding it
			// would have to start over otherwise.
			$syncToken = $this->getSyncToken();
			if (empty($changes) && $syncToken !== null) {
				$this->db->exec("COMMIT");
				$this->logger->debug("GalCache: refreshed with %d entries, no changes, sync_token=%s", count($entries), $syncToken);

				return;
			}
			$syncToken = uniqid('gal-', true);

			$changeStmt = $this->db->prepare(
				"INSERT INTO gdav_gal_changes (sync_token, uri, change_type) VALUES (:token, :uri, :type)"
			);
			foreach ($changes as $uri => $type) {
				$changeStmt->execute([':token' => $syncToken, ':uri' => $uri, ':type' => $type]);
			}
			$this->db->prepare(
				"REPLACE INTO gdav_gal_meta (key, value) VALUES ('sync_token', :token)"
			)->execute([':token' => $syncToken]);
			// the changes after a token are those with a higher id
			$this->db->prepare(
				"INSERT INTO gdav_gal_tokens (sync_token, last_change)
				 SELECT :token, COALESCE(MAX(id), 0) FROM gdav_gal_changes"
			)->execute([':token' => $syncToken]);

			// Cleanup: forget the oldest tokens and the changes before the oldest one kept.
			$this->db->prepare(
				"DELETE FROM gdav_gal_tokens WHERE sync_token NOT IN (
					SELECT sync_token FROM gdav_gal_tokens ORDER BY last_change DESC, rowid DESC LIMIT " . (int) static::KEEP_TOKENS . "
				)"
			)->execute();
			$this->db->exec("DELETE FROM gdav_gal_changes WHERE id <= (SELECT MIN(last_change) FROM gdav_gal_tokens)");

			$this->db->exec("COMMIT");
			$this->logger->debug("GalCache: refreshed with %d entries, sync_token=%s", count($entries), $syncToken);
		}
		catch (\Exception $e) {
			$this->db->exec("ROLLBACK");

			throw $e;
		}
	}

	/**
	 * Return all cached cards.
	 *
	 * @return array each element has keys: uri, etag, size, vcard_data, display_name, email
	 */
	public function getAllCards() {
		$stmt = $this->db->query("SELECT uri, etag, size, vcard_data, display_name, email FROM gdav_gal_entries");

		return $stmt->fetchAll(\PDO::FETCH_ASSOC);
	}

	/**
	 * Return a single cached card by URI.
	 *
	 * @param string $uri
	 *
	 * @return array|false
	 */
	public function getCard($uri) {
		$stmt = $this->db->prepare("SELECT uri, etag, size, vcard_data, display_name, email FROM gdav_gal_entries WHERE uri = :uri");
		$stmt->execute([':uri' => $uri]);

		return $stmt->fetch(\PDO::FETCH_ASSOC);
	}

	/**
	 * Return multiple cached cards by URI list.
	 *
	 * @return array
	 */
	public function getMultipleCards(array $uris) {
		if (empty($uris)) {
			return [];
		}
		$placeholders = implode(',', array_fill(0, count($uris), '?'));
		$stmt = $this->db->prepare("SELECT uri, etag, size, vcard_data, display_name, email FROM gdav_gal_entries WHERE uri IN ({$placeholders})");
		$stmt->execute(array_values($uris));

		return $stmt->fetchAll(\PDO::FETCH_ASSOC);
	}

	/**
	 * Return the current sync token.
	 *
	 * @return null|string
	 */
	public function getSyncToken() {
		$stmt = $this->db->prepare("SELECT value FROM gdav_gal_meta WHERE key = 'sync_token'");
		$stmt->execute();
		$row = $stmt->fetch(\PDO::FETCH_ASSOC);

		return $row ? $row['value'] : null;
	}

	/**
	 * Return changes since the given sync token.
	 *
	 * The changes of several refreshes are merged by URI. The limit is
	 * not exceeded unless the changes of a single refresh exceed it: the
	 * token returned on truncation is the one of the last refresh
	 * reported completely, so the client continues from there. An
	 * initial sync always returns all entries, it cannot be continued.
	 *
	 * @param null|string $syncToken
	 * @param null|int    $limit
	 *
	 * @return null|array null if token is unknown/expired
	 */
	public function getChanges($syncToken, $limit = null) {
		$currentToken = $this->getSyncToken();

		// If syncToken is null or empty, return all entries as "added" (initial sync).
		if ($syncToken === null || $syncToken === '') {
			$added = [];
			foreach ($this->getAllCards() as $card) {
				$added[] = $card['uri'];
			}

			return [
				'syncToken' => $currentToken ?: '0',
				'added' => $added,
				'modified' => [],
				'deleted' => [],
				'result_truncated' => false,
			];
		}

		// Also accept if the requested token *is* the current token (no changes).
		if ($syncToken === $currentToken) {
			return [
				'syncToken' => $currentToken,
				'added' => [],
				'modified' => [],
				'deleted' => [],
				'result_truncated' => false,
			];
		}

		// Check if the token is still known.
		$stmt = $this->db->prepare("SELECT last_change FROM gdav_gal_tokens WHERE sync_token = :token");
		$stmt->execute([':token' => $syncToken]);
		$lastId = $stmt->fetchColumn();
		if ($lastId === false) {
			// Token expired / unknown.
			return null;
		}

		// Collect all changes that happened *after* the given token,
		// grouped by the refresh (token) they belong to.
		$stmt = $this->db->prepare("SELECT sync_token, uri, change_type FROM gdav_gal_changes WHERE id > :id ORDER BY id ASC");
		$stmt->execute([':id' => $lastId]);
		$groups = [];
		while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
			$groups[$row['sync_token']][] = $row;
		}

		// first and last change of each URI
		$first = [];
		$last = [];
		$count = 0;
		$resultToken = $syncToken;
		$truncated = false;
		foreach ($groups as $token => $rows) {
			if ($limit > 0 && $count > 0 && $count + count($rows) > $limit) {
				$truncated = true;

				break;
			}
			foreach ($rows as $row) {
				$first[$row['uri']] ??= $row['change_type'];
				$last[$row['uri']] = $row['change_type'];
			}
			$count += count($rows);
			$resultToken = $token;
		}

		$changes = ['added' => [], 'modified' => [], 'deleted' => []];
		foreach ($first as $uri => $type) {
			$known = $type !== 'added';
			$exists = $last[$uri] !== 'deleted';
			if ($known || $exists) {
				// a card added and deleted again is not reported
				$changes[$known ? ($exists ? 'modified' : 'deleted') : 'added'][] = $uri;
			}
		}

		return [
			'syncToken' => $truncated ? $resultToken : $currentToken,
			'added' => $changes['added'],
			'modified' => $changes['modified'],
			'deleted' => $changes['deleted'],
			'result_truncated' => $truncated,
		];
	}
}
