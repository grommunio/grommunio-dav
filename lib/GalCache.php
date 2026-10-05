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
		");
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

			$syncToken = uniqid('gal-', true);

			// Detect added and modified.
			$upsert = $this->db->prepare(
				"REPLACE INTO gdav_gal_entries (entryid_hex, uri, display_name, email, vcard_data, etag, size)
				 VALUES (:eid, :uri, :dn, :email, :vcard, :etag, :size)"
			);
			$changeStmt = $this->db->prepare(
				"INSERT INTO gdav_gal_changes (sync_token, uri, change_type) VALUES (:token, :uri, :type)"
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

				if (!isset($oldEntries[$eid])) {
					$changeStmt->execute([':token' => $syncToken, ':uri' => $data['uri'], ':type' => 'added']);
				}
				elseif ($oldEntries[$eid]['etag'] !== $data['etag']) {
					$changeStmt->execute([':token' => $syncToken, ':uri' => $data['uri'], ':type' => 'modified']);
				}
			}

			// Detect deleted.
			$deleteStmt = $this->db->prepare("DELETE FROM gdav_gal_entries WHERE entryid_hex = :eid");
			foreach ($oldEntries as $eid => $old) {
				if (!isset($newMap[$eid])) {
					$changeStmt->execute([':token' => $syncToken, ':uri' => $old['uri'], ':type' => 'deleted']);
					$deleteStmt->execute([':eid' => $eid]);
				}
			}

			// Update metadata.
			$this->db->prepare(
				"REPLACE INTO gdav_gal_meta (key, value) VALUES ('last_refresh', :ts)"
			)->execute([':ts' => time()]);
			$this->db->prepare(
				"REPLACE INTO gdav_gal_meta (key, value) VALUES ('sync_token', :token)"
			)->execute([':token' => $syncToken]);

			// Cleanup: keep changes for last 2 sync tokens only.
			$cleanup = $this->db->prepare(
				"DELETE FROM gdav_gal_changes WHERE sync_token NOT IN (
					SELECT sync_token FROM gdav_gal_changes
					GROUP BY sync_token ORDER BY MAX(id) DESC LIMIT 2
				)"
			);
			$cleanup->execute();

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
	 * @param string     $syncToken
	 * @param null|mixed $limit
	 *
	 * @return null|array null if token is unknown/expired
	 */
	public function getChanges($syncToken, $limit = null) {
		// If syncToken is null or empty, return all entries as "added" (initial sync).
		if ($syncToken === null || $syncToken === '') {
			$currentToken = $this->getSyncToken();
			$cards = $this->getAllCards();
			$added = [];
			foreach ($cards as $card) {
				$added[] = $card['uri'];
			}

			$resultTruncated = false;
			if ($limit > 0 && count($added) > $limit) {
				$added = array_slice($added, 0, $limit);
				$resultTruncated = true;
			}

			return [
				'syncToken' => $currentToken ?: '0',
				'added' => $added,
				'modified' => [],
				'deleted' => [],
				'result_truncated' => $resultTruncated,
			];
		}

		// Check if the token is still known.
		$stmt = $this->db->prepare("SELECT COUNT(*) FROM gdav_gal_changes WHERE sync_token = :token");
		$stmt->execute([':token' => $syncToken]);
		$exists = (int) $stmt->fetchColumn();

		// Also accept if the requested token *is* the current token (no changes).
		$currentToken = $this->getSyncToken();
		if ($syncToken === $currentToken) {
			return [
				'syncToken' => $currentToken,
				'added' => [],
				'modified' => [],
				'deleted' => [],
				'result_truncated' => false,
			];
		}

		if ($exists === 0) {
			// Token expired / unknown.
			return null;
		}

		// Collect all changes that happened *after* the given token.
		// Changes are ordered by id; the given token's changes are the
		// baseline, so we want everything with id > max(id for that token).
		$sql = "SELECT uri, change_type FROM gdav_gal_changes WHERE id > (
				SELECT COALESCE(MAX(id), 0) FROM gdav_gal_changes WHERE sync_token = :token
			) ORDER BY id ASC";
		if ($limit > 0) {
			$sql .= ' LIMIT ' . ((int) $limit + 1);
		}
		$stmt = $this->db->prepare($sql);
		$stmt->execute([':token' => $syncToken]);

		$added = [];
		$modified = [];
		$deleted = [];
		$count = 0;
		while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
			++$count;
			if ($limit > 0 && $count > $limit) {
				break;
			}

			switch ($row['change_type']) {
				case 'added':
					$added[] = $row['uri'];
					break;

				case 'modified':
					$modified[] = $row['uri'];
					break;

				case 'deleted':
					$deleted[] = $row['uri'];
					break;
			}
		}

		return [
			'syncToken' => $currentToken,
			'added' => $added,
			'modified' => $modified,
			'deleted' => $deleted,
			'result_truncated' => $limit > 0 && $count > $limit,
		];
	}
}
