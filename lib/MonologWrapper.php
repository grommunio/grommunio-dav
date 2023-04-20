<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-only
 *
 * A wrapper for Monolog Logger class, adds a new log level: TRACE
 */

namespace grommunio\DAV;

class MonologWrapper extends \Monolog\Logger {

    /**
     * Trace information
     */
    public const TRACE = 70;

    protected static $levels = [
        self::TRACE     => 'TRACE',
        self::DEBUG     => 'DEBUG',
        self::INFO      => 'INFO',
        self::NOTICE    => 'NOTICE',
        self::WARNING   => 'WARNING',
        self::ERROR     => 'ERROR',
        self::CRITICAL  => 'CRITICAL',
        self::ALERT     => 'ALERT',
        self::EMERGENCY => 'EMERGENCY',
    ];
}
