<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2026 grommunio GmbH
 *
 * Read-only CardDAV address book that exposes the Global Address List.
 *
 * Implements SabreDAV's IDirectory interface so that clients recognise
 * it as a global directory.  All data comes from a shared SQLite cache
 * that is refreshed from MAPI when the TTL expires.
 */

namespace grommunio\DAV;

use Sabre\CardDAV\IDirectory;
use Sabre\CardDAV\Plugin;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\Exception\NotFound;
use Sabre\DAV\IMultiGet;
use Sabre\DAV\IProperties;
use Sabre\DAV\PropPatch;
use Sabre\DAV\Sync\ISyncCollection;
use Sabre\DAVACL\ACLTrait;
use Sabre\DAVACL\IACL;

class GalAddressBook implements IDirectory, IProperties, IACL, ISyncCollection, IMultiGet {
	use ACLTrait;

	public const URI = 'global-address-list';

	private $galCache;
	private $gdavBackend;
	private $principalUri;
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param string $principalUri
	 */
	public function __construct(GalCache $galCache, GrommunioDavBackend $gdavBackend, $principalUri, GLogger $logger) {
		$this->galCache = $galCache;
		$this->gdavBackend = $gdavBackend;
		$this->principalUri = $principalUri;
		$this->logger = $logger;
	}

	// ------------------------------------------------------------------ INode

	/**
	 * Returns the name of this collection.
	 *
	 * @return string
	 */
	public function getName() {
		return self::URI;
	}

	/**
	 * Renames the node -- not allowed.
	 *
	 * @param string $name
	 */
	public function setName($name) {
		throw new Forbidden('The Global Address List is read-only');
	}

	/**
	 * Returns the last modification time.
	 *
	 * @return null|int
	 */
	public function getLastModified() {
		return null;
	}

	/**
	 * Deletes this node -- not allowed.
	 */
	public function delete() {
		throw new Forbidden('The Global Address List is read-only');
	}

	// -------------------------------------------------------------- ICollection

	/**
	 * Creates a new file -- not allowed.
	 *
	 * @param string          $name
	 * @param resource|string $data
	 *
	 * @return null|string
	 */
	public function createFile($name, $data = null) {
		throw new Forbidden('The Global Address List is read-only');
	}

	/**
	 * Creates a new subdirectory -- not allowed.
	 *
	 * @param string $name
	 */
	public function createDirectory($name) {
		throw new Forbidden('The Global Address List is read-only');
	}

	/**
	 * Returns a specific child node by name.
	 *
	 * @param string $name
	 *
	 * @return GalCard
	 */
	public function getChild($name) {
		$this->ensureCacheFresh();
		$card = $this->galCache->getCard($name);
		if (!$card) {
			throw new NotFound('Card not found: ' . $name);
		}

		return new GalCard($card['uri'], $card['vcard_data'], $card['etag'], (int) $card['size'], $this->principalUri);
	}

	/**
	 * Returns all child nodes.
	 *
	 * @return GalCard[]
	 */
	public function getChildren() {
		$this->ensureCacheFresh();
		$cards = $this->galCache->getAllCards();
		$children = [];
		foreach ($cards as $card) {
			$children[] = new GalCard($card['uri'], $card['vcard_data'], $card['etag'], (int) $card['size'], $this->principalUri);
		}

		return $children;
	}

	/**
	 * Checks if a child node exists.
	 *
	 * @param string $name
	 *
	 * @return bool
	 */
	public function childExists($name) {
		$this->ensureCacheFresh();

		return $this->galCache->getCard($name) !== false;
	}

	// --------------------------------------------------------------- IMultiGet

	/**
	 * Returns multiple children by their names.
	 *
	 * @return GalCard[]
	 */
	public function getMultipleChildren(array $paths) {
		$this->ensureCacheFresh();
		$cards = $this->galCache->getMultipleCards($paths);
		$children = [];
		foreach ($cards as $card) {
			$children[] = new GalCard($card['uri'], $card['vcard_data'], $card['etag'], (int) $card['size'], $this->principalUri);
		}

		return $children;
	}

	// ---------------------------------------------------------- ISyncCollection

	/**
	 * Returns the current sync token.
	 *
	 * @return null|string
	 */
	public function getSyncToken() {
		$this->ensureCacheFresh();

		return $this->galCache->getSyncToken();
	}

	/**
	 * Returns changes since the given sync token.
	 *
	 * @param string $syncToken
	 * @param int    $syncLevel
	 * @param int    $limit
	 *
	 * @return null|array
	 */
	public function getChanges($syncToken, $syncLevel, $limit = null) {
		$this->ensureCacheFresh();

		return $this->galCache->getChanges($syncToken, $limit);
	}

	// ------------------------------------------------------------- IProperties

	/**
	 * Returns properties for this node.
	 *
	 * @param array $properties
	 *
	 * @return array
	 */
	public function getProperties($properties) {
		$response = [];
		foreach ($properties as $prop) {
			switch ($prop) {
				case '{DAV:}displayname':
					$response[$prop] = 'Global Address List';
					break;

				case '{' . Plugin::NS_CARDDAV . '}addressbook-description':
					$response[$prop] = 'Organization-wide directory (read-only)';
					break;

				case '{http://calendarserver.org/ns/}getctag':
					$this->ensureCacheFresh();
					$response[$prop] = $this->galCache->getSyncToken() ?: '0';
					break;
			}
		}

		return $response;
	}

	/**
	 * Updates properties -- not allowed.
	 */
	public function propPatch(PropPatch $propPatch) {
		throw new Forbidden('The Global Address List is read-only');
	}

	// -------------------------------------------------------------------- IACL

	/**
	 * Returns the owner principal.
	 *
	 * @return null|string
	 */
	public function getOwner() {
		return $this->principalUri;
	}

	/**
	 * Returns the read-only ACL.
	 *
	 * @return array
	 */
	public function getACL() {
		return [
			[
				'privilege' => '{DAV:}read',
				'principal' => '{DAV:}authenticated',
				'protected' => true,
			],
		];
	}

	// ------------------------------------------------------------- Cache logic

	/**
	 * Ensures the GAL cache is fresh. If the TTL has expired, fetches
	 * all entries from MAPI and refreshes the cache.
	 */
	private function ensureCacheFresh() {
		if (!$this->galCache->needsRefresh()) {
			return;
		}

		$this->logger->debug("GalAddressBook: cache expired, refreshing from MAPI");
		$entries = $this->fetchGalEntries();
		$this->galCache->refresh($entries);
	}

	/**
	 * Fetches all GAL entries via MAPI address book and converts them
	 * to vCards.
	 *
	 * @return array
	 */
	private function fetchGalEntries() {
		$session = $this->gdavBackend->GetSession();
		$ab = $this->gdavBackend->GetAddressBook();
		$galEntryId = mapi_ab_getdefaultdir($ab);
		if (!$galEntryId) {
			$this->logger->warn("GalAddressBook: unable to get GAL default dir");

			return [];
		}

		$galFolder = mapi_ab_openentry($ab, $galEntryId);
		if (!$galFolder) {
			$this->logger->warn("GalAddressBook: unable to open GAL folder");

			return [];
		}

		$table = mapi_folder_getcontentstable($galFolder, MAPI_DEFERRED_ERRORS);
		if (!$table) {
			$this->logger->warn("GalAddressBook: unable to get GAL contents table");

			return [];
		}

		$rows = mapi_table_queryallrows($table, [PR_ENTRYID, PR_DISPLAY_NAME, PR_SMTP_ADDRESS, PR_OBJECT_TYPE]);

		$entries = [];
		$usedUris = [];
		foreach ($rows as $row) {
			// Only include mail users and distribution lists.
			if (!isset($row[PR_OBJECT_TYPE])) {
				continue;
			}
			if ($row[PR_OBJECT_TYPE] !== MAPI_MAILUSER && $row[PR_OBJECT_TYPE] !== MAPI_DISTLIST) {
				continue;
			}

			$entryidHex = bin2hex($row[PR_ENTRYID]);
			$email = $row[PR_SMTP_ADDRESS] ?? '';
			$displayName = $row[PR_DISPLAY_NAME] ?? $email;

			// Build a URI from the email or entryid.
			// Do NOT rawurlencode() here — SabreDAV's encodePath() in
			// the XML serializer handles encoding for <d:href> responses.
			// Pre-encoding would cause double-encoding (%40 -> %2540).
			// Fall back to entryid if the email URI is already taken
			// (duplicate PR_SMTP_ADDRESS across entries).
			if ($email !== '' && !isset($usedUris[$email . '.vcf'])) {
				$uri = $email . '.vcf';
			}
			else {
				$uri = $entryidHex . '.vcf';
			}
			$usedUris[$uri] = true;

			// Convert to vCard via MAPI.
			$abEntry = mapi_ab_openentry($ab, $row[PR_ENTRYID]);
			if (!$abEntry) {
				$this->logger->debug("GalAddressBook: skipping entry %s, unable to open", $entryidHex);

				continue;
			}

			$vcf = mapi_mapitovcf($session, $ab, $abEntry, []);
			if (!$vcf) {
				$this->logger->debug("GalAddressBook: skipping entry %s, vCard conversion failed (0x%08X) %s", $entryidHex, mapi_last_hresult(), var_export($vcf, 1));

				continue;
			}

			$entries[] = [
				'entryid_hex' => $entryidHex,
				'uri' => $uri,
				'display_name' => $displayName,
				'email' => $email,
				'vcard_data' => $vcf,
			];
		}

		$this->logger->debug("GalAddressBook: fetched %d GAL entries from MAPI", count($entries));

		return $entries;
	}
}
