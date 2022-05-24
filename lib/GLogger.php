<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2016 - 2018 Kopano b.v.
 * SPDX-FileCopyrightText: Copyright 2020 grommunio GmbH
 *
 * A wrapper for log4php Logger.
 */

namespace grommunio\DAV;

/**
 * GLogger: wraps the php4log logger to log with better performance.
 *
 * We tried to extend \Logger but this isn't possible due to
 * the static methods depending on Logger::getHierarchy() and
 * that it cannot be overwritten (due to the use of self in Logger).
 *
 * If you want other methods of Logger please add a wrapper method to this class.
 *
 */
class GLogger {
    static protected $listOfLoggers = array();
    protected $logger;

    /**
     * Constructor
     */
    public function __construct($name) {
        $this->logger = \Logger::getLogger($name);

        // keep an output puffer in case we do debug logging
        if ($this->logger->isDebugEnabled()) {
            ob_start();
        }

        // let GLogger handle error messages
        set_error_handler('\\grommunio\\DAV\\GLogger::ErrorHandler');
    }

    /**
     * Configures log4php.
     *
     * This method needs to be called before the first logging event has
     * occurred. If this method is not called before then the default
     * configuration will be used.
     *
     * @param string|array $configuration Either a path to the configuration
     *   file, or a configuration array.
     *
     * @param string|LoggerConfigurator $configurator A custom
     * configurator class: either a class name (string), or an object which
     * implements the LoggerConfigurator interface. If left empty, the default
     * configurator implementation will be used.
     */
    public static function configure($configuration = null, $configurator = null) {
        \Logger::configure($configuration, $configurator);
    }

    /**
     * Destroy configurations for logger definitions.
     *
     * @access public
     * @return void
     */
    public function resetConfiguration() {
        \Logger::resetConfiguration();
    }

    /**
     * Returns a GLogger by name. If it does not exist, it will be created.
     *
     * @param string $name The logger name
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
     *
     * @param \Sabre\HTTP\RequestInterface $request
     *
     * @access public
     * @return void
     */
    public function LogIncoming(\Sabre\HTTP\RequestInterface $request) {
        // only do any of this is we are looking for debug messages
        if ($this->logger->isDebugEnabled()) {
            $inputHeader = $request->getMethod() . ' ' . $request->getUrl() . ' HTTP/' . $request->getHTTPVersion() . "\r\n";
            foreach ($request->getHeaders() as $key => $value) {
                if ($key === 'Authorization') {
                    list($value) = explode(' ', implode(',', $value), 2);
                    $value = [$value .' REDACTED'];
                }
                $inputHeader .= $key . ": ". implode(',', $value) . "\r\n";
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
            $this->debug("INPUT\n".$inputHeader ."\n". $inputBody);
        }
    }

    /**
     * Logs the outgoing data (headers + body) to debug.
     *
     * @param \Sabre\HTTP\ResponseInterface $response
     *
     * @access public
     * @return void
     */
    public function LogOutgoing(\Sabre\HTTP\ResponseInterface $response) {
        // only do any of this is we are looking for debug messages
        if ($this->logger->isDebugEnabled()) {
            $output = 'HTTP/'. $response->getHttpVersion() .' ' . $response->getStatus() . ' ' . $response->getStatusText() . "\n";
            foreach ($response->getHeaders() as $key => $value) {
                $output .= $key . ": ". implode(',', $value) . "\n";
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
            $this->debug("OUTPUT:\n". $output . "\n" . $outputBody);

            ob_end_flush();
        }
    }

    /**
     * Runs the arguments through sprintf() and sends it to the logger.
     *
     * @param \LoggerLevel  $level
     * @param array         $args
     * @param string        $suffix    an optional suffix that is appended to the message
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
        $this->logger->log($level, $this->getCaller(2) . $message . $suffix, null);
    }

    /**
     * Verifies if the dynamic amount of logging arguments matches the amount of variables (%) in the message.
     *
     * @param array $arguments
     * @return boolean
     */
    protected function verifyLogSyntax($arguments) {
        $count = count($arguments);
        $quoted_procent = substr_count($arguments[0], "%%");
        $t = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);

        if ($count == 0) {
            $this->logger->error(sprintf("No arguments in %s->%s() logging to '%s' in %s:%d", static::GetClassnameOnly($t[2]['class']), $t[2]['function'], $t[1]['function'], $t[1]['file'], $t[1]['line']));
            return false;
        }
        // Only check formatting if there are format parameters. Otherwise there will be
        // an error log if the $arguments[0] contain %-chars.
        if (($count > 1) && ((substr_count($arguments[0], "%") - $quoted_procent*2) !== $count-1)) {
            $this->logger->error(sprintf("Wrong number of arguments in %s->%s() logging to '%s' in %s:%d", static::GetClassnameOnly($t[2]['class']), $t[2]['function'], $t[1]['function'], $t[1]['file'], $t[1]['line']));
            return false;
        }
        return true;
    }

    /**
     * Returns a string in the form of "Class->Method(): " or "file:line" if requested.
     *
     * @param number    $level      the level you want the info from, default 1
     * @param boolean   $fileline   returns "file:line" if set to true.
     * @return string
     */
    protected function getCaller($level = 1, $fileline = false) {
        $wlevel = $level+1;
        $t = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, $wlevel+1);
        if (isset( $t[$wlevel]['function'])) {
            if ($fileline) {
                return $t[$wlevel]['file']. ":" . $t[$wlevel]['line'];
            }
            return $this->GetClassnameOnly($t[$wlevel]['class']) .'->'. $t[$wlevel]['function']. '(): ';
        }
        return '';
    }

    /**
     * Format bytes to a more human readable value.
     *
     * @param int $bytes
     * @param int $precision
     *
     * @access public
     * @return string
     */
    public function FormatBytes($bytes, $precision = 2) {
        if ($bytes <= 0) return '0 B';

        $units = array('B', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB', 'EiB', 'ZiB');
        $base = log ($bytes, 1024);
        $fBase = floor($base);
        $pow = pow(1024, $base - $fBase);
        return sprintf ("%.{$precision}f %s", $pow, $units[$fBase]);
    }

    /**
     * The GrommunioDav error handler.
     *
     * @param int $errno
     * @param string $errstr
     * @param string $errfile
     * @param int $errline
     * @param mixed $errcontext
     */
    public static function ErrorHandler($errno, $errstr, $errfile, $errline, $errcontext) {
        // this is from Z-Push but might be helpful in the future: https://wiki.z-hub.io/x/sIEa
        if (defined('LOG_ERROR_MASK')) $errno &= LOG_ERROR_MASK;

        switch ($errno) {
            case 0:
                // logging disabled by LOG_ERROR_MASK
                break;

            case E_DEPRECATED:
                // do not handle this message
                break;

            case E_NOTICE:
            case E_WARNING:
                $logger = \Logger::getLogger('error');
                $logger->warn("$errfile:$errline $errstr ($errno)");
                break;

            default:
                $bt = debug_backtrace();
                $logger = \Logger::getLogger('error');
                $logger->error("trace error: $errfile:$errline $errstr ($errno) - backtrace: ". (count($bt)-1) . " steps");
                for($i = 1, $bt_length = count($bt); $i < $bt_length; $i++) {
                    $file = $line = "unknown";
                    if (isset($bt[$i]['file'])) $file = $bt[$i]['file'];
                    if (isset($bt[$i]['line'])) $line = $bt[$i]['line'];
                    $logger->error("trace: $i:". $file . ":" . $line. " - " . ((isset($bt[$i]['class']))? $bt[$i]['class'] . $bt[$i]['type']:""). $bt[$i]['function']. "()");
                }
                break;
        }
    }

    /**
     * Wrapper of the \Logger class
     */

    /**
     * Log a message object with the TRACE level.
     * It has the same footprint as sprintf(), but arguments are only processed
     * if the loglevel is activated.
     *
     * @param mixed $message message
     * @param mixed ...params
     *
     * @access public
     * @return void
     */
    public function trace() {
        if (DEVELOPER_MODE) {
            if (!$this->verifyLogSyntax(func_get_args())) {
                return;
            }
        }
        if ($this->logger->isTraceEnabled()) {
            $this->writeLog(\LoggerLevel::getLevelTrace(), func_get_args());
        }
    }

    /**
     * Log a message object with the DEBUG level.
     * It has the same footprint as sprintf(), but arguments are only processed
     * if the loglevel is activated.
     *
     * @param mixed $message message
     * @param mixed ...params
     *
     * @access public
     * @return void
     */
    public function debug() {
        if (DEVELOPER_MODE) {
            if (!$this->verifyLogSyntax(func_get_args())) {
                return;
            }
        }
        if ($this->logger->isDebugEnabled()) {
            $this->writeLog(\LoggerLevel::getLevelDebug(), func_get_args());
        }
    }

    /**
     * Log a message object with the INFO level.
     * It has the same footprint as sprintf(), but arguments are only processed
     * if the loglevel is activated.
     *
     * @param mixed $message message
     * @param mixed ...params
     *
     * @access public
     * @return void
     */
    public function info() {
        if (DEVELOPER_MODE) {
            if (!$this->verifyLogSyntax(func_get_args())) {
                return;
            }
        }
        if ($this->logger->isInfoEnabled()) {
            $this->writeLog(\LoggerLevel::getLevelInfo(), func_get_args());
        }
    }

    /**
     * Log a message object with the WARN level.
     * It has the same footprint as sprintf(), but arguments are only processed
     * if the loglevel is activated.
     *
     * @param mixed $message message
     * @param mixed ...params
     *
     * @access public
     * @return void
     */
    public function warn() {
        if (DEVELOPER_MODE) {
            if (!$this->verifyLogSyntax(func_get_args())) {
                return;
            }
        }
        if ($this->logger->isWarnEnabled()) {
            $this->writeLog(\LoggerLevel::getLevelWarn(), func_get_args(), ' - '. $this->getCaller(1, true));
        }
    }

    /**
     * Log a message object with the ERROR level.
     * It has the same footprint as sprintf(), but arguments are only processed
     * if the loglevel is activated.
     *
     * @param mixed $message message
     * @param mixed ...params
     *
     * @access public
     * @return void
     */
    public function error() {
        if (DEVELOPER_MODE) {
            if (!$this->verifyLogSyntax(func_get_args())) {
                return;
            }
        }
        if ($this->logger->isErrorEnabled()) {
            $this->writeLog(\LoggerLevel::getLevelError(), func_get_args(), ' - '. $this->getCaller(1, true));
        }
    }

    /**
     * Log a message object with the FATAL level.
     * It has the same footprint as sprintf(), but arguments are only processed
     * if the loglevel is activated.
     *
     * @param mixed $message message
     * @param mixed ...params
     *
     * @access public
     * @return void
     */
    public function fatal() {
        if (DEVELOPER_MODE) {
            if (!$this->verifyLogSyntax(func_get_args())) {
                return;
            }
        }
        if ($this->logger->isFatalEnabled()) {
            $this->writeLog(\LoggerLevel::getLevelFatal(), func_get_args(), ' - '. $this->getCaller(1, true));
        }
    }
}
