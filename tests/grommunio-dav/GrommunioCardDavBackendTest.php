<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2016 - 2018 Kopano b.v.
 * SPDX-FileCopyrightText: Copyright 2020 grommunio GmbH
 *
 * Tests for grommunio Card DAV backend class which handles
 * contact related activities.
 */

namespace grommunio\DAV;

/**
 * @internal
 *
 * @coversNothing
 */
class GrommunioCardDavBackendTest extends \PHPUnit_Framework_TestCase {
	private $gDavBackendMock;
	private $kCardDavBackend;

	/**
	 * @see PHPUnit_Framework_TestCase::setUp()
	 */
	protected function setUp() {
		$gloggerMock = $this->getMockBuilder(GLogger::class)->disableOriginalConstructor()->getMock();
		$this->gDavBackendMock = $this->getMockBuilder(GrommunioDavBackend::class)->disableOriginalConstructor()->getMock();
		$this->kCardDavBackend = new GrommunioCardDavBackend($this->gDavBackendMock, $gloggerMock);
	}

	/**
	 * @see PHPUnit_Framework_TestCase::tearDown()
	 */
	protected function tearDown() {
		$this->kCardDavBackend = null;
		$this->gDavBackendMock = null;
	}

	/**
	 * Tests if the constructor is created without errors.
	 */
	public function testConstruct() {
		$this->assertTrue(is_object($this->kCardDavBackend));
	}
}
