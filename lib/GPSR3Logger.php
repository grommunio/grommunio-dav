<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2016 - 2018 Kopano b.v.
 * SPDX-FileCopyrightText: Copyright 2020 grommunio GmbH
 *
 * Wrapper to get a PSR-3 compatible interface out of an php4log logger.
 */

namespace grommunio\DAV;

class GPSR3Logger implements \Psr\Log\LoggerInterface {
	/**
	 * log4php.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Wraps a log4php logger into a PSR-3 compatible interface.
	 *
	 * @param Logger $logger
	 */
	public function __construct($logger) {
		$this->logger = $logger;
	}

	/**
	 * Emergency message, like system down.
	 *
	 * @param string $message
	 */
	public function emergency($message, array $context = []) {
		$this->logger->fatal($this->interpret($message, $context));
	}

	/**
	 * Immediate Action required.
	 *
	 * @param string $message
	 */
	public function alert($message, array $context = []) {
		$this->logger->fatal($this->interpret($message, $context));
	}

	/**
	 * Critical messages.
	 *
	 * @param string $message
	 */
	public function critical($message, array $context = []) {
		$this->logger->fatal($this->interpret($message, $context));
	}

	/**
	 * Errors happening on runtime that need to be logged.
	 *
	 * @param string $message
	 */
	public function error($message, array $context = []) {
		$this->logger->error($this->interpret($message, $context));
	}

	/**
	 * Warnings (not necessarily errors).
	 *
	 * @param string $message
	 */
	public function warning($message, array $context = []) {
		$this->logger->warn($this->interpret($message, $context));
	}

	/**
	 * Significant events (still normal).
	 *
	 * @param string $message
	 */
	public function notice($message, array $context = []) {
		$this->logger->info($this->interpret($message, $context));
	}

	/**
	 * Events with informational value.
	 *
	 * @param string $message
	 */
	public function info($message, array $context = []) {
		$this->logger->info($this->interpret($message, $context));
	}

	/**
	 * Debug data.
	 *
	 * @param string $message
	 */
	public function debug($message, array $context = []) {
		$this->logger->debug($this->interpret($message, $context));
	}

	/**
	 * Logs at a loglevel.
	 *
	 * @param mixed  $level
	 * @param string $message
	 */
	public function log($level, $message, array $context = []) {
		throw new \Exception('Please call specific logging message');
	}

	/**
	 * Interprets context values as string like in the PSR-3 example implementation.
	 *
	 * @param string $message
	 *
	 * @return string
	 */
	protected function interpret($message, array $context = []) {
		$replace = [];
		foreach ($context as $key => $val) {
			$replace['{' . $key . '}'] = $val;
		}

		return strtr($message, $replace);
	}
}
