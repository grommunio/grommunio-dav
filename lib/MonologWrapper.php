<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2023-2024 grommunio GmbH
 *
 * A wrapper for Monolog Logger class, adds a new log level: TRACE
 */

namespace grommunio\DAV;

use Monolog\Logger;

class MonologWrapper extends Logger {
	/**
	 * Trace information.
	 */
	public const TRACE = 70;

	protected static $levels = [
		self::TRACE => 'TRACE',
		self::DEBUG => 'DEBUG',
		self::INFO => 'INFO',
		self::NOTICE => 'NOTICE',
		self::WARNING => 'WARNING',
		self::ERROR => 'ERROR',
		self::CRITICAL => 'CRITICAL',
		self::ALERT => 'ALERT',
		self::EMERGENCY => 'EMERGENCY',
	];
}
