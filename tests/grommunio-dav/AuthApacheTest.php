<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2026 grommunio GmbH
 *
 * Tests for the authentication by the HTTP server.
 */

namespace grommunio\DAV;

use PHPUnit\Framework\TestCase;
use Sabre\DAV\Exception\NotImplemented;
use Sabre\HTTP\Request;
use Sabre\HTTP\Response;

/**
 * @internal
 *
 * @coversNothing
 */
class AuthApacheTest extends TestCase {
	private function backend(): AuthApache {
		$logger = $this->getMockBuilder(GLogger::class)->disableOriginalConstructor()->getMock();
		$backend = new AuthApache($logger);
		$backend->setRealm('grommunio dav');

		return $backend;
	}

	public function testChallengeHasRealm() {
		$response = new Response();
		$this->backend()->challenge(new Request('GET', '/'), $response);
		$this->assertSame('Basic realm="grommunio dav", charset="UTF-8"', $response->getHeader('WWW-Authenticate'));
	}

	public function testWithoutUser() {
		$request = new Request('GET', '/');
		$request->setRawServerData([]);
		$this->assertFalse($this->backend()->check($request, new Response())[0]);
	}

	public function testUserCannotLogOn() {
		$request = new Request('GET', '/');
		$request->setRawServerData(['REMOTE_USER' => 'user@example.com']);
		$this->expectException(NotImplemented::class);
		$this->backend()->check($request, new Response());
	}
}
