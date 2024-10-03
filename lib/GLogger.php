<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2016 - 2018 Kopano b.v.
 * SPDX-FileCopyrightText: Copyright 2020-2024 grommunio GmbH
 *
 * A wrapper for Monolog Logger.
 */

namespace grommunio\DAV;

use grommunio\DAV\MonologWrapper as Logger;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\ErrorLogHandler;
use Monolog\Handler\NullHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Processor\ProcessIdProcessor;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;

/**
 * GLogger: wraps the monolog Logger.
 *
 * If you want other methods of Logger please add a wrapper method to this class.
 */
class GLogger {
	protected static $listOfLoggers = [];
	protected static $parentLogger;
	protected $logger;

	/**
	 * Constructor.
	 *
	 * @param mixed $name
	 */
	public function __construct($name) {
		$this->logger = self::$parentLogger->withName($name);

		// keep an output puffer in case we do debug logging
		if ($this->logger->isHandling(Logger::DEBUG)) {
			ob_start();
		}

		// let GLogger handle error messages
		set_error_handler('\grommunio\DAV\GLogger::ErrorHandler');
	}

	/**
	 * Configures parent monolog Logger.
	 *
	 * This method needs to be called before the first logging event has
	 * occurred.
	 *
	 * @param array|string $configuration either a path to the configuration
	 *                                    file, or a configuration array
	 */
	public static function configure($configuration = null) {
		// Load configuration from ini-file if a file path (string) is given
		if (is_string($configuration)) {
			$configuration = parse_ini_file($configuration);
			if (!is_array($configuration)) {
				throw new \Exception('Invalid GLogger configuration file');
			}
		}
		elseif (!is_array($configuration)) {
			throw new \Exception('GLogger configuration should be either a string with path to the configuration file, or a configuration array');
		}

		// Log level
		if (!isset($configuration['level'])) {
			$configuration['level'] = 'INFO';
		}
		elseif (!is_string($configuration['level'])) {
			throw new \Exception('GLogger configuration parameter "level" is not a string');
		}

		$configuration['level'] = strtoupper($configuration['level']);
		$allLogLevels = Logger::getLevels();

		if (!isset($allLogLevels[$configuration['level']])) {
			throw new \Exception('GLogger configuration parameter "level" is not known');
		}

		$logLevel = $allLogLevels[$configuration['level']];

		// Parent logger class
		static::$parentLogger = new Logger('');

		// Without configuration parameter 'file' all log messages will go to error_log()
		if (isset($configuration['file'])) {
			if (!is_string($configuration['file'])) {
				throw new \Exception('GLogger configuration parameter "file" is not a string');
			}

			$stream = new StreamHandler($configuration['file'], $logLevel);
		}
		else {
			$stream = new ErrorLogHandler(ErrorLogHandler::OPERATING_SYSTEM, $logLevel);
		}

		// Log messages formatting
		$lineFormat = null;

		if (isset($configuration['lineFormat']) && is_string($configuration['lineFormat'])) {
			$lineFormat = stripcslashes($configuration['lineFormat']);
		} // stripcslashes to recognize "\n"

		$timeFormat = null;

		if (isset($configuration['timeFormat']) && is_string($configuration['timeFormat'])) {
			$timeFormat = $configuration['timeFormat'];
		}

		if ($lineFormat || $timeFormat) {
			$formatter = new LineFormatter($lineFormat, $timeFormat, true, true);
			$stream->setFormatter($formatter);
		}

		static::$parentLogger->pushHandler($stream);

		// Add processor id (pid) to log messages
		static::$parentLogger->pushProcessor(new ProcessIdProcessor());
	}

	/**
	 * Destroy configurations for logger definitions.
	 */
	public function resetConfiguration() {
		if (static::$parentLogger) {
			static::$parentLogger->reset();
			static::$parentLogger->pushHandler(new NullHandler());
		}
		$this->logger = self::$parentLogger;
	}

	public function getGPSR3Logger() {
		return $this->logger;
	}

	/**
	 * Returns a GLogger by name. If it does not exist, it will be created.
	 *
	 * @param mixed $class
	 *
	 * @return Logger
	 */
	public static function GetLogger($class) {
		if (!isset(static::$listOfLoggers[$class])) {
			static::$listOfLoggers[$class] = new GLogger(static::GetClassnameOnly($class));
		}

		return static::$listOfLoggers[$class];
	}

	/**
	 * Cuts of the namespace and returns just the classname.
	 *
	 * @param string $namespaceWithClass
	 *
	 * @return string
	 */
	protected static function GetClassnameOnly($namespaceWithClass) {
		if (strpos($namespaceWithClass, '\\') == false) {
			return $namespaceWithClass;
		}

		return substr(strrchr($namespaceWithClass, '\\'), 1);
	}

	/**
	 * Logs the incoming data (headers + body) to debug.
	 */
	public function LogIncoming(RequestInterface $request) {
		// only do any of this is we are looking for debug messages
		if ($this->logger->isHandling(Logger::DEBUG)) {
			$inputHeader = $request->getMethod() . ' ' . $request->getUrl() . ' HTTP/' . $request->getHTTPVersion() . "\r\n";
			foreach ($request->getHeaders() as $key => $value) {
				if ($key === 'Authorization') {
					list($value) = explode(' ', implode(',', $value), 2);
					$value = [$value . ' REDACTED'];
				}
				$inputHeader .= $key . ": " . implode(',', $value) . "\r\n";
			}
			// reopen the input so we can read it (again)
			$inputBody = stream_get_contents(fopen('php://input', 'r'));
			// format incoming xml to be better human readable
			if (stripos($inputBody, '<?xml') === 0) {
				$dom = new \DOMDocument('1.0', 'utf-8');
				$dom->preserveWhiteSpace = false;
				$dom->formatOutput = true;
				$dom->recover = true;
				$dom->loadXML($inputBody);
				$inputBody = $dom->saveXML();
			}
			// log incoming data
			$this->debug("INPUT\n" . $inputHeader . "\n" . $inputBody);
		}
	}

	/**
	 * Logs the outgoing data (headers + body) to debug.
	 */
	public function LogOutgoing(ResponseInterface $response) {
		// only do any of this is we are looking for debug messages
		if ($this->logger->isHandling(Logger::DEBUG)) {
			$output = 'HTTP/' . $response->getHttpVersion() . ' ' . $response->getStatus() . ' ' . $response->getStatusText() . "\n";
			foreach ($response->getHeaders() as $key => $value) {
				$output .= $key . ": " . implode(',', $value) . "\n";
			}
			$outputBody = ob_get_contents();
			if (stripos($outputBody, '<?xml') === 0) {
				$dom = new \DOMDocument('1.0', 'utf-8');
				$dom->preserveWhiteSpace = false;
				$dom->formatOutput = true;
				$dom->recover = true;
				$dom->loadXML($outputBody);
				$outputBody = $dom->saveXML();
			}
			$this->debug("OUTPUT:\n" . $output . "\n" . $outputBody);

			ob_end_flush();
		}
	}

	/**
	 * Runs the arguments through sprintf() and sends it to the logger.
	 *
	 * @param int    $level
	 * @param array  $args
	 * @param string $suffix an optional suffix that is appended to the message
	 */
	protected function writeLog($level, $args, $suffix = '') {
		$outArgs = [];
		foreach ($args as $arg) {
			if (is_array($arg)) {
				$outArgs[] = print_r($arg, true);
			}
			$outArgs[] = $arg;
		}
		// Call sprintf() with the arguments only if there are format parameters because
		// otherwise sprintf will complain about too few arguments.
		// This also prevents throwing errors if there are %-chars in the $outArgs.
		$message = (count($outArgs) > 1) ? call_user_func_array('sprintf', $outArgs) : $outArgs[0];
		// prepend class+method and log the message
		$this->logger->log($level, $this->getCaller(2) . $message . $suffix);
	}

	/**
	 * Verifies if the dynamic amount of logging arguments matches the amount of variables (%) in the message.
	 *
	 * @param array $arguments
	 *
	 * @return bool
	 */
	protected function verifyLogSyntax($arguments) {
		$count = count($arguments);
		$quoted_procent = substr_count($arguments[0], "%%");
		$t = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);

		if ($count == 0) {
			$this->logger->error(sprintf(
				"No arguments in %s->%s() logging to '%s' in %s:%d",
				static::GetClassnameOnly($t[2]['class']),
				$t[2]['function'],
				$t[1]['function'],
				$t[1]['file'],
				$t[1]['line']
			));

			return false;
		}
		// Only check formatting if there are format parameters. Otherwise there will be
		// an error log if the $arguments[0] contain %-chars.
		if (($count > 1) && ((substr_count($arguments[0], "%") - $quoted_procent * 2) !== $count - 1)) {
			$this->logger->error(sprintf(
				"Wrong number of arguments in %s->%s() logging to '%s' in %s:%d",
				static::GetClassnameOnly($t[2]['class']),
				$t[2]['function'],
				$t[1]['function'],
				$t[1]['file'],
				$t[1]['line']
			));

			return false;
		}

		return true;
	}

	/**
	 * Returns a string in the form of "Class->Method(): " or "file:line" if requested.
	 *
	 * @param number $level    the level you want the info from, default 1
	 * @param bool   $fileline returns "file:line" if set to true
	 *
	 * @return string
	 */
	protected function getCaller($level = 1, $fileline = false) {
		$wlevel = $level + 1;
		$t = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, $wlevel + 1);
		if (isset($t[$wlevel]['function'])) {
			if ($fileline) {
				return ($t[$wlevel]['file'] ?? "unknown file") . ":" . ($t[$wlevel]['line'] ?? "unknown line");
			}

			return $this->GetClassnameOnly($t[$wlevel]['class']) . '->' . $t[$wlevel]['function'] . '(): ';
		}

		return '';
	}

	/**
	 * Format bytes to a more human readable value.
	 *
	 * @param int $bytes
	 * @param int $precision
	 *
	 * @return string
	 */
	public function FormatBytes($bytes, $precision = 2) {
		if ($bytes <= 0) {
			return '0 B';
		}

		$units = ['B', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB', 'EiB', 'ZiB'];
		$base = log($bytes, 1024);
		$fBase = floor($base);
		$pow = pow(1024, $base - $fBase);

		return sprintf("%.{$precision}f %s", $pow, $units[$fBase]);
	}

	/**
	 * The GrommunioDav error handler.
	 *
	 * @param int    $errno
	 * @param string $errstr
	 * @param string $errfile
	 * @param int    $errline
	 * @param mixed  $errcontext
	 */
	public static function ErrorHandler($errno, $errstr, $errfile, $errline, $errcontext = []) {
		if (defined('LOG_ERROR_MASK')) {
			$errno &= \LOG_ERROR_MASK;
		}

		$logger = new GLogger('error');

		switch ($errno) {
			case 0:
				// logging disabled by LOG_ERROR_MASK
				break;

			case E_DEPRECATED:
				// do not handle this message
				break;

			case E_NOTICE:
			case E_WARNING:
				$logger->warn("{$errfile}:{$errline} {$errstr} ({$errno})");
				break;

			default:
				$bt = debug_backtrace();
				$logger->error("trace error: {$errfile}:{$errline} {$errstr} ({$errno}) - backtrace: " . (count($bt) - 1) . " steps");
				for ($i = 1, $bt_length = count($bt); $i < $bt_length; ++$i) {
					$file = $bt[$i]['file'] ?? "unknown file";
					$line = $bt[$i]['line'] ?? "unknown line";

					$logger->error("trace {$i}: {$file}:{$line} - " . (isset($bt[$i]['class']) ? $bt[$i]['class'] . $bt[$i]['type'] : "") . $bt[$i]['function'] . "()");
				}
				break;
		}
	}

	/**
	 * Wrapper of the Logger class.
	 */

	/**
	 * Log a message object with the TRACE level.
	 * It has the same footprint as sprintf(), but arguments are only processed
	 * if the loglevel is activated.
	 *
	 * @param mixed $message message
	 */
	public function trace(...$message) {
		if (DEVELOPER_MODE && !$this->verifyLogSyntax($message)) {
			return;
		}
		if ($this->logger->isHandling(Logger::TRACE)) {
			$this->writeLog(Logger::TRACE, $message);
		}
	}

	/**
	 * Log a message object with the DEBUG level.
	 * It has the same footprint as sprintf(), but arguments are only processed
	 * if the loglevel is activated.
	 *
	 * @param mixed $message message
	 */
	public function debug(...$message) {
		if (DEVELOPER_MODE && !$this->verifyLogSyntax($message)) {
			return;
		}
		if ($this->logger->isHandling(Logger::DEBUG)) {
			$this->writeLog(Logger::DEBUG, $message);
		}
	}

	/**
	 * Log a message object with the INFO level.
	 * It has the same footprint as sprintf(), but arguments are only processed
	 * if the loglevel is activated.
	 *
	 * @param mixed $message message
	 */
	public function info(...$message) {
		if (DEVELOPER_MODE && !$this->verifyLogSyntax($message)) {
			return;
		}
		if ($this->logger->isHandling(Logger::INFO)) {
			$this->writeLog(Logger::INFO, $message);
		}
	}

	/**
	 * Log a message object with the WARN level.
	 * It has the same footprint as sprintf(), but arguments are only processed
	 * if the loglevel is activated.
	 *
	 * @param mixed $message message
	 */
	public function warn(...$message) {
		if (DEVELOPER_MODE && !$this->verifyLogSyntax($message)) {
			return;
		}
		if ($this->logger->isHandling(Logger::WARNING)) {
			$this->writeLog(Logger::WARNING, $message, ' - ' . $this->getCaller(1, true));
		}
	}

	/**
	 * Log a message object with the ERROR level.
	 * It has the same footprint as sprintf(), but arguments are only processed
	 * if the loglevel is activated.
	 *
	 * @param mixed $message message
	 */
	public function error(...$message) {
		if (DEVELOPER_MODE && !$this->verifyLogSyntax($message)) {
			return;
		}
		if ($this->logger->isHandling(Logger::ERROR)) {
			$this->writeLog(Logger::ERROR, $message, ' - ' . $this->getCaller(1, true));
		}
	}

	/**
	 * Log a message object with the FATAL level.
	 * It has the same footprint as sprintf(), but arguments are only processed
	 * if the loglevel is activated.
	 *
	 * @param mixed $message message
	 */
	public function fatal(...$message) {
		if (DEVELOPER_MODE && !$this->verifyLogSyntax($message)) {
			return;
		}
		if ($this->logger->isHandling(Logger::CRITICAL)) {
			$this->writeLog(Logger::CRITICAL, $message, ' - ' . $this->getCaller(1, true));
		}
	}
}
