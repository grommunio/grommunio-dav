<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2026 grommunio GmbH
 *
 * Tests for the formatting of log messages.
 */

namespace grommunio\DAV;

use PHPUnit\Framework\TestCase;

/**
 * @internal
 *
 * @coversNothing
 */
class GLoggerTest extends TestCase {
	private $file;

	protected function setUp(): void {
		$this->file = tempnam(sys_get_temp_dir(), 'gdavlog');
		GLogger::configure(['level' => 'DEBUG', 'file' => $this->file, 'lineFormat' => "%message%\n"]);
	}

	protected function tearDown(): void {
		restore_error_handler();
		ob_end_clean();
		unlink($this->file);
	}

	public function testArrayArgumentKeepsPlaceholders() {
		$logger = new GLogger('test');
		$logger->debug("first %s second %s third %d", ['key' => 'value'], 'after', 42);

		$log = file_get_contents($this->file);
		$this->assertStringContainsString("first Array\n(\n    [key] => value\n)\n second after third 42", $log);
	}
}
