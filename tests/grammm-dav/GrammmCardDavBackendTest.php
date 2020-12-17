<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2016 - 2018 Kopano b.v.
 * SPDX-FileCopyrightText: Copyright 2020 grammm GmbH
 *
 * Tests for grammm Card DAV backend class which handles
 * contact related activities.
 */

namespace grammm\DAV;

class GrammmCardDavBackendTest extends \PHPUnit_Framework_TestCase {
    private $gDavBackendMock;
    private $kCardDavBackend;


    /**
     *
     * {@inheritDoc}
     * @see PHPUnit_Framework_TestCase::setUp()
     */
    protected function setUp() {
        $gloggerMock = $this->getMockBuilder(GLogger::class)->disableOriginalConstructor()->getMock();
        $this->gDavBackendMock = $this->getMockBuilder(GrammmDavBackend::class)->disableOriginalConstructor()->getMock();
        $this->kCardDavBackend = new GrammmCardDavBackend($this->gDavBackendMock, $gloggerMock);
    }

    /**
     *
     * {@inheritDoc}
     * @see PHPUnit_Framework_TestCase::tearDown()
     */
    protected function tearDown() {
        $this->kCardDavBackend = null;
        $this->gDavBackendMock = null;
    }

    /**
     * Tests if the constructor is created without errors.
     *
     * @access public
     * @return void
     */
    public function testConstruct() {
        $this->assertTrue(is_object($this->kCardDavBackend));
    }
}
