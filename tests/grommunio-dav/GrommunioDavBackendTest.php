<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2016 - 2018 Kopano b.v.
 * SPDX-FileCopyrightText: Copyright 2020 grommunio GmbH
 *
 * Tests for grommunio DAV backend class which handles grommunio related activities.
 */

namespace grommunio\DAV;

/**
 * @internal
 *
 * @coversNothing
 */
class GrommunioDavBackendTest extends \PHPUnit_Framework_TestCase
{
	protected $gDavBackend;

	/**
	 * {@inheritDoc}
	 *
	 * @see PHPUnit_Framework_TestCase::setUp()
	 */
	protected function setUp()
	{
		$gloggerMock = $this->getMockBuilder(GLogger::class)->disableOriginalConstructor()->getMock();
		$this->gDavBackend = new GrommunioDavBackend($gloggerMock);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @see PHPUnit_Framework_TestCase::tearDown()
	 */
	protected function tearDown()
	{
		$this->gDavBackend = null;
	}

	/**
	 * Tests if the constructor is created without errors.
	 */
	public function testConstruct()
	{
		$this->assertTrue(is_object($this->gDavBackend));
	}

	/**
	 * Tests the GetObjectIdFromObjectUri function.
	 *
	 * @param string $objectUri
	 * @param string $extension
	 * @param string $expected
	 *
	 * @dataProvider ObjectUriProvider
	 */
	public function testGetObjectIdFromObjectUri($objectUri, $extension, $expected)
	{
		$this->assertEquals($expected, $this->gDavBackend->GetObjectIdFromObjectUri($objectUri, $extension));
	}

	/**
	 * Provides data for testGetObjectIdFromObjectUri.
	 *
	 * @return array
	 */
	public function ObjectUriProvider()
	{
		return [
			['1234.ics', '.ics', '1234'],               // ok, cut .ics
			['5678AF.vcf', '.vcf', '5678AF'],           // ok, cut .vcf
			['123400.vcf', '.ics', '123400.vcf'],       // different extension, return as is
			['1234.ics', '.vcf', '1234.ics'],            // different extension, return as is
		];
	}
}
