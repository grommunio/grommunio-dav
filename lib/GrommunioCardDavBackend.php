<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2016 - 2018 Kopano b.v.
 * SPDX-FileCopyrightText: Copyright 2020 - 2024 grommunio GmbH
 *
 * grommunio Card DAV backend class which handles contact related activities.
 */

namespace grommunio\DAV;

use Sabre\CardDAV\Backend\AbstractBackend;
use Sabre\CardDAV\Backend\SyncSupport;
use Sabre\DAV\PropPatch;

class GrommunioCardDavBackend extends AbstractBackend implements SyncSupport {
	private $logger;
	protected $gDavBackend;

	public const FILE_EXTENSION = '.vcf';
	public const MESSAGE_CLASSES = ['IPM.Contact'];
	public const CONTAINER_CLASS = 'IPF.Contact';
	public const CONTAINER_CLASSES = ['IPF.Contact'];

	/**
	 * Constructor.
	 */
	public function __construct(GrommunioDavBackend $gDavBackend, GLogger $glogger) {
		$this->gDavBackend = $gDavBackend;
		$this->logger = $glogger;
	}

	/**
	 * Returns the list of addressbooks for a specific user.
	 *
	 * Every addressbook should have the following properties:
	 *   id - an arbitrary unique id
	 *   uri - the 'basename' part of the url
	 *   principaluri - Same as the passed parameter
	 *
	 * Any additional clark-notation property may be passed besides this. Some
	 * common ones are :
	 *   {DAV:}displayname
	 *   {urn:ietf:params:xml:ns:carddav}addressbook-description
	 *   {http://calendarserver.org/ns/}getctag
	 *
	 * @param string $principalUri
	 *
	 * @return array
	 */
	public function getAddressBooksForUser($principalUri) {
		$this->logger->trace("principalUri: %s", $principalUri);

		return $this->gDavBackend->GetFolders($principalUri, static::CONTAINER_CLASSES);
	}

	/**
	 * Updates properties for an address book.
	 *
	 * The list of mutations is stored in a Sabre\DAV\PropPatch object.
	 * To do the actual updates, you must tell this object which properties
	 * you're going to process with the handle() method.
	 *
	 * Calling the handle method is like telling the PropPatch object "I
	 * promise I can handle updating this property".
	 *
	 * Read the PropPatch documentation for more info and examples.
	 *
	 * @param string $addressBookId
	 */
	public function updateAddressBook($addressBookId, PropPatch $propPatch) {
		// TODO is our logger able to log this object? It probably needs to be adapted.
		$this->logger->trace("addressBookId: %s - proppatch: %s", $addressBookId, $propPatch);
	}

	/**
	 * Creates a new address book.
	 *
	 * This method should return the id of the new address book. The id can be
	 * in any format, including ints, strings, arrays or objects.
	 *
	 * @param string $principalUri
	 * @param string $url          just the 'basename' of the url
	 *
	 * @return mixed
	 */
	public function createAddressBook($principalUri, $url, array $properties) {
		$this->logger->trace("principalUri: %s - url: %s - properties: %s", $principalUri, $url, $properties);

		// TODO Add displayname
		return $this->gDavBackend->CreateFolder($principalUri, $url, static::CONTAINER_CLASS, "");
	}

	/**
	 * Deletes an entire addressbook and all its contents.
	 *
	 * @param mixed $addressBookId
	 */
	public function deleteAddressBook($addressBookId) {
		$this->logger->trace("addressBookId: %s", $addressBookId);
		$success = $this->gDavBackend->DeleteFolder($addressBookId);
		// TODO evaluate $success
	}

	/**
	 * Returns all cards for a specific addressbook id.
	 *
	 * This method should return the following properties for each card:
	 *   * carddata - raw vcard data
	 *   * uri - Some unique url
	 *   * lastmodified - A unix timestamp
	 *
	 * It's recommended to also return the following properties:
	 *   * etag - A unique etag. This must change every time the card changes.
	 *   * size - The size of the card in bytes.
	 *
	 * If these last two properties are provided, less time will be spent
	 * calculating them. If they are specified, you can also omit carddata.
	 * This may speed up certain requests, especially with large cards.
	 *
	 * @param mixed $addressbookId
	 *
	 * @return array
	 */
	public function getCards($addressbookId) {
		$result = $this->gDavBackend->GetObjects($addressbookId, static::FILE_EXTENSION, ['types' => static::MESSAGE_CLASSES]);
		$this->logger->trace("addressbookId: %s found %d objects", $addressbookId, count($result));

		return $result;
	}

	/**
	 * Returns a specific card.
	 *
	 * The same set of properties must be returned as with getCards. The only
	 * exception is that 'carddata' is absolutely required.
	 *
	 * If the card does not exist, you must return false.
	 *
	 * @param mixed    $addressBookId
	 * @param string   $cardUri
	 * @param resource $mapifolder    optional mapifolder resource, used if available
	 *
	 * @return array|bool
	 */
	public function getCard($addressBookId, $cardUri, $mapifolder = null) {
		$this->logger->trace("addressBookId: %s - cardUri: %s", $addressBookId, $cardUri);

		if (!$mapifolder) {
			$mapifolder = $this->gDavBackend->GetMapiFolder($addressBookId);
		}

		$mapimessage = $this->gDavBackend->GetMapiMessageForId($addressBookId, $cardUri, $mapifolder, static::FILE_EXTENSION);
		if (!$mapimessage) {
			$this->logger->debug("Object NOT FOUND");

			return false;
		}

		$realId = $this->gDavBackend->GetIdOfMapiMessage($addressBookId, $mapimessage);

		$session = $this->gDavBackend->GetSession();
		$ab = $this->gDavBackend->GetAddressBook();

		$vcf = mapi_mapitovcf($session, $ab, $mapimessage, []);
		$this->logger->trace("vcf generated by mapi_mapitovcf: %s%s", PHP_EOL, $vcf);
		$props = mapi_getprops($mapimessage, [PR_LAST_MODIFICATION_TIME]);
		$r = [
			'id' => $realId,
			'uri' => $realId . static::FILE_EXTENSION,
			'etag' => '"' . $props[PR_LAST_MODIFICATION_TIME] . '"',
			'lastmodified' => $props[PR_LAST_MODIFICATION_TIME],
			'carddata' => $vcf,
			'size' => strlen($vcf),
			'addressbookid' => $addressBookId,
		];

		$this->logger->trace("returned data id: %s - size: %d - etag: %s", $r['id'], $r['size'], $r['etag']);

		return $r;
	}

	/**
	 * Creates a new card.
	 *
	 * The addressbook id will be passed as the first argument. This is the
	 * same id as it is returned from the getAddressBooksForUser method.
	 *
	 * The cardUri is a base uri, and doesn't include the full path. The
	 * cardData argument is the vcard body, and is passed as a string.
	 *
	 * It is possible to return an ETag from this method. This ETag is for the
	 * newly created resource, and must be enclosed with double quotes (that
	 * is, the string itself must contain the double quotes).
	 *
	 * You should only return the ETag if you store the carddata as-is. If a
	 * subsequent GET request on the same card does not have the same body,
	 * byte-by-byte and you did return an ETag here, clients tend to get
	 * confused.
	 *
	 * If you don't return an ETag, you can just return null.
	 *
	 * @param mixed  $addressBookId
	 * @param string $cardUri
	 * @param string $cardData
	 *
	 * @return null|string
	 */
	public function createCard($addressBookId, $cardUri, $cardData) {
		$this->logger->trace("addressBookId: %s - cardUri: %s - cardData: %s", $addressBookId, $cardUri, $cardData);
		$objectId = $this->gDavBackend->GetObjectIdFromObjectUri($cardUri, static::FILE_EXTENSION);
		$folder = $this->gDavBackend->GetMapiFolder($addressBookId);
		$mapimessage = $this->gDavBackend->CreateObject($addressBookId, $folder, $objectId);

		return $this->setData($addressBookId, $mapimessage, $cardData);
	}

	/**
	 * Updates a card.
	 *
	 * The addressbook id will be passed as the first argument. This is the
	 * same id as it is returned from the getAddressBooksForUser method.
	 *
	 * The cardUri is a base uri, and doesn't include the full path. The
	 * cardData argument is the vcard body, and is passed as a string.
	 *
	 * It is possible to return an ETag from this method. This ETag should
	 * match that of the updated resource, and must be enclosed with double
	 * quotes (that is: the string itself must contain the actual quotes).
	 *
	 * You should only return the ETag if you store the carddata as-is. If a
	 * subsequent GET request on the same card does not have the same body,
	 * byte-by-byte and you did return an ETag here, clients tend to get
	 * confused.
	 *
	 * If you don't return an ETag, you can just return null.
	 *
	 * @param mixed  $addressBookId
	 * @param string $cardUri
	 * @param string $cardData
	 *
	 * @return null|string
	 */
	public function updateCard($addressBookId, $cardUri, $cardData) {
		$this->logger->trace("addressBookId: %s - cardUri: %s - cardData: %s", $addressBookId, $cardUri, $cardData);

		$mapimessage = $this->gDavBackend->GetMapiMessageForId($addressBookId, $cardUri, null, static::FILE_EXTENSION);

		return $this->setData($addressBookId, $mapimessage, $cardData);
	}

	/**
	 * Sets data for a contact.
	 *
	 * @param mixed  $addressBookId
	 * @param mixed  $mapimessage
	 * @param string $vcf
	 *
	 * @return null|string
	 */
	private function setData($addressBookId, $mapimessage, $vcf) {
		$this->logger->trace("mapimessage: %s - vcf: %s", $mapimessage, $vcf);
		$store = $this->gDavBackend->GetStoreById($addressBookId);
		$session = $this->gDavBackend->GetSession();

		$ok = mapi_vcftomapi($session, $store, $mapimessage, $vcf);
		if ($ok) {
			mapi_savechanges($mapimessage);
			$props = mapi_getprops($mapimessage);

			return '"' . $props[PR_LAST_MODIFICATION_TIME] . '"';
		}

		return null;
	}

	/**
	 * Deletes a card.
	 *
	 * @param mixed  $addressBookId
	 * @param string $cardUri
	 *
	 * @return bool
	 */
	public function deleteCard($addressBookId, $cardUri) {
		$this->logger->trace("addressBookId: %s - cardUri: %s", $addressBookId, $cardUri);
		$mapifolder = $this->gDavBackend->GetMapiFolder($addressBookId);
		$objectId = $this->gDavBackend->GetObjectIdFromObjectUri($cardUri, static::FILE_EXTENSION);

		// to delete we need the PR_ENTRYID of the message
		// TODO move this part to GrommunioDavBackend
		$mapimessage = $this->gDavBackend->GetMapiMessageForId($addressBookId, $cardUri, $mapifolder, static::FILE_EXTENSION);
		$props = mapi_getprops($mapimessage, [PR_ENTRYID]);
		mapi_folder_deletemessages($mapifolder, [$props[PR_ENTRYID]]);

		return true;
	}

	/**
	 * The getChanges method returns all the changes that have happened, since
	 * the specified syncToken in the specified address book.
	 *
	 * This function should return an array, such as the following:
	 *
	 * [
	 *   'syncToken' => 'The current synctoken',
	 *   'added'   => [
	 *      'new.txt',
	 *   ],
	 *   'modified'   => [
	 *      'modified.txt',
	 *   ],
	 *   'deleted' => [
	 *      'foo.php.bak',
	 *      'old.txt'
	 *   ]
	 * ];
	 *
	 * The returned syncToken property should reflect the *current* syncToken
	 * of the calendar, as reported in the {http://sabredav.org/ns}sync-token
	 * property. This is needed here too, to ensure the operation is atomic.
	 *
	 * If the $syncToken argument is specified as null, this is an initial
	 * sync, and all members should be reported.
	 *
	 * The modified property is an array of nodenames that have changed since
	 * the last token.
	 *
	 * The deleted property is an array with nodenames, that have been deleted
	 * from collection.
	 *
	 * The $syncLevel argument is basically the 'depth' of the report. If it's
	 * 1, you only have to report changes that happened only directly in
	 * immediate descendants. If it's 2, it should also include changes from
	 * the nodes below the child collections. (grandchildren)
	 *
	 * The $limit argument allows a client to specify how many results should
	 * be returned at most. If the limit is not specified, it should be treated
	 * as infinite.
	 *
	 * If the limit (infinite or not) is higher than you're willing to return,
	 * you should throw a Sabre\DAV\Exception\TooMuchMatches() exception.
	 *
	 * If the syncToken is expired (due to data cleanup) or unknown, you must
	 * return null.
	 *
	 * The limit is 'suggestive'. You are free to ignore it.
	 *
	 * @param string $addressBookId
	 * @param string $syncToken
	 * @param int    $syncLevel
	 * @param int    $limit
	 *
	 * @return array
	 */
	public function getChangesForAddressBook($addressBookId, $syncToken, $syncLevel, $limit = null) {
		$this->logger->trace("addressBookId: %s - syncToken: %s - syncLevel: %d - limit: %d", $addressBookId, $syncToken, $syncLevel, $limit);

		return $this->gDavBackend->Sync($addressBookId, $syncToken, static::FILE_EXTENSION, $limit, ['types' => static::MESSAGE_CLASSES]);
	}
}
