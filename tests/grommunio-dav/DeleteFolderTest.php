<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2026 grommunio GmbH
 *
 * Tests for deleting collections.
 */

namespace grommunio\DAV;

use PHPUnit\Framework\TestCase;
use Sabre\DAV\Exception\NotFound;

// MAPI functions of the backend while a test of this file runs, the calls
// are recorded in $GLOBALS['deleteFolderMapi'], otherwise the real ones
function mapi_getprops($obj, $tags) {
	if (!isset($GLOBALS['deleteFolderMapi'])) {
		return \mapi_getprops($obj, $tags);
	}
	return $GLOBALS['deleteFolderMapi']['props'][$obj] ?? [];
}

function mapi_msgstore_openentry($store, $entryid = null) {
	if (!isset($GLOBALS['deleteFolderMapi'])) {
		return \mapi_msgstore_openentry($store, $entryid);
	}
	return $entryid;
}

function mapi_folder_copyfolder($src, $entryid, $dest, $name, $flags) {
	if (!isset($GLOBALS['deleteFolderMapi'])) {
		return \mapi_folder_copyfolder($src, $entryid, $dest, $name, $flags);
	}
	$GLOBALS['deleteFolderMapi']['calls'][] = ['move', $entryid, $dest, $name];
	$taken = in_array($name, $GLOBALS['deleteFolderMapi']['taken'], true);
	$GLOBALS['deleteFolderMapi']['hresult'] = $taken ? MAPI_E_COLLISION : 0;

	return !$taken;
}

function mapi_folder_deletefolder($parent, $entryid, $flags) {
	if (!isset($GLOBALS['deleteFolderMapi'])) {
		return \mapi_folder_deletefolder($parent, $entryid, $flags);
	}
	$GLOBALS['deleteFolderMapi']['calls'][] = ['delete', $entryid];
	$GLOBALS['deleteFolderMapi']['hresult'] = 0;

	return true;
}

function mapi_last_hresult() {
	if (!isset($GLOBALS['deleteFolderMapi'])) {
		return \mapi_last_hresult();
	}
	return $GLOBALS['deleteFolderMapi']['hresult'] ?? 0;
}

function mapi_folder_gethierarchytable($folder, $flags = 0) {
	if (!isset($GLOBALS['deleteFolderMapi'])) {
		return \mapi_folder_gethierarchytable($folder, $flags);
	}
	return 'hierarchy';
}

function mapi_table_queryallrows($table, $tags = []) {
	if (!isset($GLOBALS['deleteFolderMapi'])) {
		return \mapi_table_queryallrows($table, $tags);
	}
	return [];
}

/**
 * @internal
 *
 * @coversNothing
 */
class DeleteFolderTest extends TestCase {
	protected function tearDown(): void {
		unset($GLOBALS['deleteFolderMapi']);
	}

	private function backend(string $parent) {
		$GLOBALS['deleteFolderMapi'] = [
			'calls' => [],
			'taken' => [],
			'props' => [
				'folder' => [PR_ENTRYID => 'folder-eid', PR_PARENT_ENTRYID => $parent, PR_DISPLAY_NAME => 'Work'],
				'store' => [PR_IPM_WASTEBASKET_ENTRYID => 'wastebasket-eid'],
			],
		];
		$backend = $this->getMockBuilder(GrommunioDavBackend::class)->disableOriginalConstructor()->onlyMethods(['GetMapiFolder', 'GetStoreById'])->getMock();
		$backend->method('GetMapiFolder')->willReturn('folder');
		$backend->method('GetStoreById')->willReturn('store');
		$logger = $this->getMockBuilder(GLogger::class)->disableOriginalConstructor()->getMock();
		(fn () => $this->logger = $logger)->call($backend);

		return $backend;
	}

	public function testFolderIsMovedToDeletedItems() {
		$this->backend('calendar-parent-eid')->DeleteFolder('u:abc');
		$this->assertSame([['move', 'folder-eid', 'wastebasket-eid', 'Work']], $GLOBALS['deleteFolderMapi']['calls']);
	}

	public function testTakenNameInDeletedItemsGetsANumber() {
		$backend = $this->backend('calendar-parent-eid');
		$GLOBALS['deleteFolderMapi']['taken'] = ['Work', 'Work (1)'];
		$backend->DeleteFolder('u:abc');
		$this->assertSame(['move', 'folder-eid', 'wastebasket-eid', 'Work (2)'], end($GLOBALS['deleteFolderMapi']['calls']));
	}

	public function testFolderInDeletedItemsIsDeleted() {
		$this->backend('wastebasket-eid')->DeleteFolder('u:abc');
		$this->assertSame([['delete', 'folder-eid']], $GLOBALS['deleteFolderMapi']['calls']);
	}
}
