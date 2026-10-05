<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2026 grommunio GmbH
 *
 * Converts personal distribution lists from and to vCard groups.
 */

namespace grommunio\DAV;

use Sabre\VObject\Component\VCard;
use Sabre\VObject\Reader;

class DistList {
	public const MESSAGE_CLASS = 'IPM.DistList';

	private const ICON_INDEX = 0x00000202;
	private const ONEOFF_GUID = "\x81\x2b\x1f\xa4\xbe\xa3\x10\x19\x9d\x6e\x00\xdd\x01\x0f\x54\x02";

	private $logger;
	private $gDavBackend;
	private $properties = [];

	/**
	 * Constructor.
	 */
	public function __construct(GrommunioDavBackend $gDavBackend, GLogger $glogger) {
		$this->gDavBackend = $gDavBackend;
		$this->logger = $glogger;
	}

	/**
	 * Checks if the message class is a distribution list.
	 *
	 * @param string $messageClass
	 *
	 * @return bool
	 */
	public static function IsDistListClass($messageClass) {
		return strcasecmp((string) $messageClass, self::MESSAGE_CLASS) === 0 ||
			stripos((string) $messageClass, self::MESSAGE_CLASS . '.') === 0;
	}

	/**
	 * Returns the parsed vCard if it is a group.
	 *
	 * @param string $vcf
	 *
	 * @return null|VCard
	 */
	public static function ParseGroupVCard($vcf) {
		try {
			$vcard = Reader::read($vcf, Reader::OPTION_FORGIVING);
		}
		catch (\Exception $e) {
			return null;
		}
		if (!$vcard instanceof VCard) {
			return null;
		}
		foreach (['KIND', 'X-ADDRESSBOOKSERVER-KIND'] as $name) {
			if (isset($vcard->{$name}) && strcasecmp(trim((string) $vcard->{$name}), 'group') === 0) {
				return $vcard;
			}
		}

		return null;
	}

	/**
	 * Returns the vCard UID of a message, falls back to the object id.
	 *
	 * @param string $addressBookId
	 * @param mixed  $mapimessage
	 *
	 * @return string
	 */
	public function GetUid($addressBookId, $mapimessage) {
		return $this->GetStoredUid($addressBookId, $mapimessage) ??
			rawurldecode($this->gDavBackend->GetIdOfMapiMessage($addressBookId, $mapimessage));
	}

	/**
	 * Returns the vCard UID stored with a message.
	 *
	 * @param string $addressBookId
	 * @param mixed  $mapimessage
	 *
	 * @return null|string
	 */
	public function GetStoredUid($addressBookId, $mapimessage) {
		$properties = $this->getProperties($addressBookId);
		$props = mapi_getprops($mapimessage, [$properties['vcarduid']]);

		if (empty($props[$properties['vcarduid']])) {
			return null;
		}

		return $props[$properties['vcarduid']];
	}

	/**
	 * Converts a distribution list to a vCard.
	 *
	 * @param string $addressBookId
	 * @param mixed  $mapimessage
	 *
	 * @return string
	 */
	public function ToVCard($addressBookId, $mapimessage) {
		$properties = $this->getProperties($addressBookId);
		$props = mapi_getprops($mapimessage, [
			PR_DISPLAY_NAME,
			PR_SUBJECT,
			PR_BODY,
			PR_LAST_MODIFICATION_TIME,
			$properties['dlname'],
			$properties['members'],
			$properties['oneoff_members'],
			$properties['categories'],
		]);

		$name = $props[PR_DISPLAY_NAME] ?? $props[$properties['dlname']] ?? $props[PR_SUBJECT] ?? '';

		$vcard = new VCard([
			'VERSION' => '4.0',
			'PRODID' => '-//grommunio//grommunio-dav//EN',
			'UID' => $this->GetUid($addressBookId, $mapimessage),
			'KIND' => 'group',
			'FN' => $name,
			'N' => [$name, '', '', '', ''],
		]);
		foreach ($this->getMembers($addressBookId, $props, $properties) as $member) {
			if ($member['uri'] !== null) {
				$vcard->add('MEMBER', $member['uri']);
			}
		}
		if (!empty($props[$properties['categories']])) {
			$vcard->add('CATEGORIES', $props[$properties['categories']]);
		}
		if (isset($props[PR_BODY]) && $props[PR_BODY] !== '') {
			$vcard->add('NOTE', $props[PR_BODY]);
		}
		if (isset($props[PR_LAST_MODIFICATION_TIME])) {
			$vcard->add('REV', gmdate('Ymd\THis\Z', $props[PR_LAST_MODIFICATION_TIME]));
		}

		return $vcard->serialize();
	}

	/**
	 * Sets the distribution list properties from a vCard group.
	 *
	 * @param string $addressBookId
	 * @param mixed  $mapimessage
	 * @param VCard  $vcard
	 *
	 * @return bool
	 */
	public function FromVCard($addressBookId, $mapimessage, VCard $vcard) {
		$properties = $this->getProperties($addressBookId);
		$current = mapi_getprops($mapimessage, [$properties['members'], $properties['oneoff_members']]);

		// keep the original entries of unchanged members (GAL, other folders)
		$known = [];
		foreach ($this->getMembers($addressBookId, $current, $properties) as $member) {
			if ($member['uri'] !== null) {
				$known[strtolower($member['uri'])] ??= $member;
			}
		}

		$members = [];
		$oneoffs = [];
		$seen = [];
		$uris = array_merge($vcard->select('MEMBER'), $vcard->select('X-ADDRESSBOOKSERVER-MEMBER'));
		foreach ($uris as $uri) {
			$uri = trim((string) $uri);
			$key = strtolower($uri);
			if ($uri === '' || isset($seen[$key])) {
				continue;
			}
			$seen[$key] = true;
			$member = $known[$key] ?? $this->createMember($addressBookId, $uri, $properties);
			if ($member === null) {
				$this->logger->info("DistList: member %s not found", $uri);

				continue;
			}
			$members[] = $member['member'];
			$oneoffs[] = $member['oneoff'];
		}

		$name = trim((string) ($vcard->FN ?? ''));
		if ($name === '' && isset($vcard->N)) {
			$name = trim(implode(' ', array_filter((array) $vcard->N->getParts())));
		}

		$props = [
			PR_MESSAGE_CLASS => self::MESSAGE_CLASS,
			PR_ICON_INDEX => self::ICON_INDEX,
			PR_DISPLAY_NAME => $name,
			PR_SUBJECT => $name,
			$properties['dlname'] => $name,
			$properties['fileas'] => $name,
		];
		// OL would still use the old stream
		$delete = [$properties['stream'], $properties['checksum']];

		if (isset($vcard->UID) && (string) $vcard->UID !== '') {
			$props[$properties['vcarduid']] = (string) $vcard->UID;
		}
		if (!empty($members)) {
			$props[$properties['members']] = $members;
			$props[$properties['oneoff_members']] = $oneoffs;
		}
		else {
			$delete[] = $properties['members'];
			$delete[] = $properties['oneoff_members'];
		}
		$categories = [];
		foreach ($vcard->select('CATEGORIES') as $property) {
			$categories = array_merge($categories, $property->getParts());
		}
		$categories = array_values(array_unique(array_filter($categories)));
		if (!empty($categories)) {
			$props[$properties['categories']] = $categories;
		}
		else {
			$delete[] = $properties['categories'];
		}
		if (isset($vcard->NOTE) && (string) $vcard->NOTE !== '') {
			$props[PR_BODY] = (string) $vcard->NOTE;
		}
		else {
			array_push($delete, PR_BODY, PR_HTML, PR_RTF_COMPRESSED);
		}

		mapi_deleteprops($mapimessage, $delete);
		if (!mapi_setprops($mapimessage, $props)) {
			$this->logger->error("DistList: unable to set properties: 0x%08X", mapi_last_hresult());

			return false;
		}

		return true;
	}

	/**
	 * Removes the distribution list members.
	 *
	 * @param string $addressBookId
	 * @param mixed  $mapimessage
	 */
	public function DeleteMembers($addressBookId, $mapimessage) {
		$properties = $this->getProperties($addressBookId);
		mapi_deleteprops($mapimessage, [
			$properties['members'],
			$properties['oneoff_members'],
			$properties['stream'],
			$properties['checksum'],
			$properties['dlname'],
		]);
	}

	/**
	 * Returns the members of a distribution list with their vCard MEMBER value.
	 *
	 * @param string $addressBookId
	 * @param array  $props
	 * @param array  $properties
	 *
	 * @return array
	 */
	private function getMembers($addressBookId, $props, $properties) {
		$members = $props[$properties['members']] ?? [];
		$oneoffs = $props[$properties['oneoff_members']] ?? [];
		$result = [];
		foreach ($members as $i => $member) {
			$oneoff = $oneoffs[$i] ?? $member;
			$result[] = [
				'member' => $member,
				'oneoff' => $oneoff,
				'uri' => $this->getMemberUri($addressBookId, $member, $oneoff),
			];
		}

		return $result;
	}

	/**
	 * Returns the vCard MEMBER value of a member.
	 *
	 * @param string $addressBookId
	 * @param string $member
	 * @param string $oneoff
	 *
	 * @return null|string
	 */
	private function getMemberUri($addressBookId, $member, $oneoff) {
		if (strlen($member) > 21 && substr($member, 4, 16) === WAB_GUID) {
			$kind = ord($member[20]) & 0x0F;
			$entryid = substr($member, 21);
			if ($kind === 3 || $kind === 4) {
				// contact or distribution list
				$uri = $this->getStoreMemberUri($addressBookId, $entryid);
				if ($uri !== null) {
					return $uri;
				}
			}
			elseif ($kind === 5 || $kind === 6) {
				// GAL user or distribution list
				$ab = $this->gDavBackend->GetAddressBook();
				$abentry = mapi_ab_openentry($ab, $entryid);
				if ($abentry) {
					$abprops = mapi_getprops($abentry, [PR_SMTP_ADDRESS]);
					if (!empty($abprops[PR_SMTP_ADDRESS])) {
						return 'mailto:' . $abprops[PR_SMTP_ADDRESS];
					}
				}
			}
		}

		$parsed = mapi_parseoneoff(substr($member, 4, 16) === self::ONEOFF_GUID ? $member : $oneoff);
		if (is_array($parsed) && strcasecmp($parsed['type'] ?? '', 'SMTP') === 0 &&
			str_contains($parsed['address'] ?? '', '@')) {
			return 'mailto:' . $parsed['address'];
		}

		return null;
	}

	/**
	 * Returns the urn:uuid of a member in the same folder.
	 *
	 * @param string $addressBookId
	 * @param string $entryid
	 *
	 * @return null|string
	 */
	private function getStoreMemberUri($addressBookId, $entryid) {
		$store = $this->gDavBackend->GetStoreById($addressBookId);
		$message = mapi_msgstore_openentry($store, $entryid);
		if (!$message) {
			return null;
		}
		$props = mapi_getprops($message, [PR_PARENT_SOURCE_KEY]);
		$arr = explode(':', $addressBookId);
		if (!isset($props[PR_PARENT_SOURCE_KEY], $arr[1]) || bin2hex($props[PR_PARENT_SOURCE_KEY]) !== strtolower($arr[1])) {
			return null;
		}
		$uid = $this->GetUid($addressBookId, $message);

		return stripos($uid, 'urn:') === 0 ? $uid : 'urn:uuid:' . $uid;
	}

	/**
	 * Creates the member entries for a vCard MEMBER value.
	 *
	 * @param string $addressBookId
	 * @param string $uri
	 * @param array  $properties
	 *
	 * @return null|array
	 */
	private function createMember($addressBookId, $uri, $properties) {
		if (stripos($uri, 'mailto:') === 0) {
			$address = rawurldecode(strtok(substr($uri, 7), '?'));

			return $this->createOneOffMember($address);
		}

		$uid = stripos($uri, 'urn:uuid:') === 0 ? substr($uri, 9) : $uri;
		$message = $this->gDavBackend->GetMapiMessageForId($addressBookId, $uid, null, GrommunioCardDavBackend::FILE_EXTENSION);
		if (!$message && $uid !== $uri) {
			$message = $this->gDavBackend->GetMapiMessageForId($addressBookId, $uri, null, GrommunioCardDavBackend::FILE_EXTENSION);
		}
		if (!$message) {
			return str_contains($uri, '@') && !str_contains($uri, ':') ? $this->createOneOffMember($uri) : null;
		}

		$props = mapi_getprops($message, [
			PR_ENTRYID,
			PR_MESSAGE_CLASS,
			PR_DISPLAY_NAME,
			$properties['email1address'],
			$properties['email1type'],
		]);
		if (!isset($props[PR_ENTRYID])) {
			return null;
		}
		$name = $props[PR_DISPLAY_NAME] ?? '';
		if (self::IsDistListClass($props[PR_MESSAGE_CLASS] ?? '')) {
			$type = DL_DIST;
			$oneoff = mapi_createoneoff($name, 'MAPIPDL', 'Unknown', MAPI_UNICODE);
		}
		else {
			$type = DL_USER;
			$address = $props[$properties['email1address']] ?? '';
			$addrtype = $props[$properties['email1type']] ?? '';
			$oneoff = mapi_createoneoff($name, $addrtype !== '' ? $addrtype : 'SMTP', $address !== '' ? $address : 'Unknown', MAPI_UNICODE);
		}
		if ($oneoff === false) {
			return null;
		}

		return [
			'member' => pack('V', 0) . WAB_GUID . chr($type) . $props[PR_ENTRYID],
			'oneoff' => $oneoff,
		];
	}

	/**
	 * Creates the member entries for an e-mail address.
	 *
	 * @param string $address
	 *
	 * @return null|array
	 */
	private function createOneOffMember($address) {
		$address = trim($address);
		if ($address === '') {
			return null;
		}
		$oneoff = mapi_createoneoff($address, 'SMTP', $address, MAPI_UNICODE);
		if ($oneoff === false) {
			return null;
		}

		return ['member' => $oneoff, 'oneoff' => $oneoff];
	}

	/**
	 * Returns the distribution list properties.
	 *
	 * @param string $addressBookId
	 *
	 * @return array
	 */
	private function getProperties($addressBookId) {
		$storeId = explode(':', $addressBookId)[0];
		if (!isset($this->properties[$storeId])) {
			$this->properties[$storeId] = getPropIdsFromStrings($this->gDavBackend->GetStoreById($addressBookId), [
				"vcarduid" => "PT_STRING8:PSETID_GROMOX:vcarduid",
				"fileas" => "PT_STRING8:PSETID_Address:0x8005",
				"checksum" => "PT_LONG:PSETID_Address:0x804C",
				"dlname" => "PT_STRING8:PSETID_Address:0x8053",
				"oneoff_members" => "PT_MV_BINARY:PSETID_Address:0x8054",
				"members" => "PT_MV_BINARY:PSETID_Address:0x8055",
				"stream" => "PT_BINARY:PSETID_Address:0x8064",
				"email1type" => "PT_STRING8:PSETID_Address:" . PidLidEmail1AddressType,
				"email1address" => "PT_STRING8:PSETID_Address:" . PidLidEmail1EmailAddress,
				"categories" => "PT_MV_STRING8:PS_PUBLIC_STRINGS:Keywords",
			]);
		}

		return $this->properties[$storeId];
	}
}
