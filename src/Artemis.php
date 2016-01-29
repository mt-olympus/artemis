<?php
namespace Artemis;

class Artemis
{
    /** @var Artemis */
    public static $instance = null;

    private $logDir;

    private $hideFields = [
        'password'
    ];

    public function __construct($config)
    {
        if (!isset($config['log_dir'])) {
            $config['log_dir'] = 'data/kharon/artemis';
        }
        $this->logDir = $config['log_dir'];

        if (isset($config['hide_fields'])) {
            $this->hideFields = $config['hide_fields'];
        }
    }

    public static function init($config = [], $useExceptionHandler = true, $useErrorHandler = true, $useFatalErrors = true)
    {
        if (!isset($config['log_dir'])) {
            $config['log_dir'] = 'data/kharon/artemis';
        }

        if (!file_exists($config['log_dir']) || !is_writable($config['log_dir'])) {
            return null;
        }

        self::$instance = new Artemis($config);

        if ($useExceptionHandler) {
            set_exception_handler([self::$instance, 'handleException']);
        }
        if ($useErrorHandler) {
            set_error_handler([self::$instance, 'handleError']);
        }
        if ($useFatalErrors) {
            register_shutdown_function([self::$instance, 'handleFatal']);
        }

        return self::$instance;
    }

    public function handleException($exception, $extra = null, $payload = null)
    {
        $data = [
            'time' => microtime(true),
        ];

        $data['body']['trace'] = $this->getExceptionTrace($exception, $extra);

        // request, server, person data
        $data['request'] = $this->getRequest();

        $data['server'] = $this->getServer();
        $data['person'] = $this->getUser();

        if (is_array($payload)) {
            array_merge_recursive($data, $payload);
        }

        $logFile = $this->logDir . '/exception-' . getmypid() . '-' . microtime(true) . '.kharon';
        file_put_contents($logFile, json_encode($data, null, 100));
    }

    public function handleFatal()
    {
        $last_error = error_get_last();
        if ($last_error != null) {
            switch ($last_error['type']) {
                case E_PARSE:
                case E_ERROR:
                    $this->handleError($last_error['type'], $last_error['message'], $last_error['file'], $last_error['line']);
                    break;
            }
        }
    }

    public function handleError($errno, $errstr, $errfile, $errline)
    {
        $data = [
            'time' => microtime(true),
        ];

        // set error level and error constant name
        $level = 'info';
        $constant = '#' . $errno;
        switch ($errno) {
            case 1:
                $level = 'error';
                $constant = 'E_ERROR';
                break;
            case 2:
                $level = 'warning';
                $constant = 'E_WARNING';
                break;
            case 4:
                $level = 'critical';
                $constant = 'E_PARSE';
            case 8:
                $level = 'info';
                $constant = 'E_NOTICE';
                break;
            case 256:
                $level = 'error';
                $constant = 'E_USER_ERROR';
                break;
            case 512:
                $level = 'warning';
                $constant = 'E_USER_WARNING';
                break;
            case 1024:
                $level = 'info';
                $constant = 'E_USER_NOTICE';
                break;
            case 2048:
                $level = 'info';
                $constant = 'E_STRICT';
                break;
            case 4096:
                $level = 'error';
                $constant = 'E_RECOVERABLE_ERROR';
                break;
            case 8192:
                $level = 'info';
                $constant = 'E_DEPRECATED';
                break;
            case 16384:
                $level = 'info';
                $constant = 'E_USER_DEPRECATED';
                break;
        }
        $data['level'] = $level;

        $error_class = $constant . ': ' . $errstr;

        $data['body'] = array(
            'trace' => array(
                'frames' => $this->getErrorData($errfile, $errline),
                'exception' => array(
                    'class' => $error_class
                )
            )
        );

        // request, server, person data
        $data['request'] = $this->getRequest();
        $data['server'] = $this->getServer();
        $data['person'] = $this->getUser();

        $logFile = $this->logDir . '/error-' . getmypid() . '-' . microtime(true) . '.kharon';
        file_put_contents($logFile, json_encode($data, null, 100));
    }

    private function getExceptionTrace($exception, $extra = null)
    {
        $message = $exception->getMessage();

        $frames = [];
        $frames[] = [
            'filename' => $exception->getFile(),
            'lineno' => $exception->getLine()
        ];

        foreach ($exception->getTrace() as $frame) {
            $frames[] = [
                'filename' => isset($frame['file']) ? $frame['file'] : '<internal>',
                'lineno' => isset($frame['line']) ? $frame['line'] : 0,
                'method' => $frame['function']
            ];
        }

        $trace = [
            'frames' => $frames,
            'exception' => array(
                'class' => get_class($exception),
                'message' => ! empty($message) ? $message : ''
            ),
            'extra' => $extra,
        ];

        $previous = $exception->getPrevious();
        if ($previous) {
            $trace['previous'] = $this->getExceptionTrace($previous);
        }

        return $trace;
    }

    private function getRequest()
    {
        $request = array(
            'url' => $this->handleUrl($this->getUrl()),
            'user_ip' => $this->getRemoteIp(),
            'headers' => $this->getHeaders(),
            'method' => isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : null
        );

        if ($_GET) {
            $request['GET'] = $this->hideFields($_GET);
        }
        if ($_POST) {
            $request['POST'] = $this->hideFields($_POST);
        }
        if (isset($_SESSION) && $_SESSION) {
            $request['session'] = $this->hideFields($_SESSION);
        }

        return $request;
    }

    private function getUrl()
    {
        $proto = 'http';
        if (! empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
            $proto = strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']);
        } elseif (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == 'on') {
            $proto = 'https';
        }

        $host = 'unknown';
        if (! empty($_SERVER['HTTP_X_FORWARDED_HOST'])) {
            $host = $_SERVER['HTTP_X_FORWARDED_HOST'];
        } elseif (! empty($_SERVER['HTTP_HOST'])) {
            $parts = explode(':', $_SERVER['HTTP_HOST']);
            $host = $parts[0];
        } elseif (! empty($_SERVER['SERVER_NAME'])) {
            $host = $_SERVER['SERVER_NAME'];
        }

        $port = 80;
        if (! empty($_SERVER['HTTP_X_FORWARDED_PORT'])) {
            $port = $_SERVER['HTTP_X_FORWARDED_PORT'];
        } elseif (! empty($_SERVER['SERVER_PORT'])) {
            $port = $_SERVER['SERVER_PORT'];
        } elseif ($proto === 'https') {
            $port = 443;
        }

        $path = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/';

        $url = $proto . '://' . $host;

        if (($proto == 'https' && $port != 443) || ($proto == 'http' && $port != 80)) {
            $url .= ':' . $port;
        }

        $url .= $path;

        return $url;
    }

    private function getRemoteIp()
    {
        $forwarded = isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? $_SERVER['HTTP_X_FORWARDED_FOR'] : null;
        if ($forwarded) {
            $parts = explode(',', $forwarded);
            return $parts[0];
        }
        $realIp = isset($_SERVER['HTTP_X_REAL_IP']) ? $_SERVER['HTTP_X_REAL_IP'] : null;
        if ($realIp) {
            return $realIp;
        }
        return isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : null;
    }

    private function getHeaders()
    {
        $headers = [];
        foreach ($_SERVER as $key => $val) {
            if (substr($key, 0, 5) == 'HTTP_') {
                $name = strtolower(substr($key, 5));
                if (strpos($name, '_') !== false) {
                    $name = str_replace(' ', '-', ucwords(str_replace('_', ' ', $name)));
                } else {
                    $name = ucfirst($name);
                }
                $headers[$name] = $val;
            }
        }

        return $headers;
    }

    private function handleUrl($url)
    {
        $query = parse_url($url, PHP_URL_QUERY);
        if (!$query) {
            return $url;
        }
        $parsed = [];
        parse_str($query, $parsed);
        $params = $this->hideFields($parsed, 'x');
        $new = str_replace($query, http_build_query($params), $url);
        return $new;
    }

    private function hideParams($params, $token = '*')
    {
        $new = [];
        foreach ($params as $key => $value) {
            if (in_array($key, $this->hideFields)) {
                $new[$key] = $token;
            } elseif (is_array($value)) {
                $new[$key] = $this->hideParams($value, $token);
            } else {
                $new[$key] = $value;
            }
        }

        return $new;
    }

    private function getServer()
    {
        $server = [
            'host' => gethostname(),
        ];
        return $server;
    }

    private function getUser()
    {
        //TODO
        return null;
    }

    private function getErrorData($errfile, $errline)
    {
        $frames = [];
        $backtrace = debug_backtrace();
        foreach ($backtrace as $frame) {
            if (isset($frame['file']) && $frame['file'] == __FILE__) {
                continue;
            }
            if ($frame['function'] == 'report_php_error' && count($frames) == 0) {
                continue;
            }

            $frames[] = [
                'filename' => isset($frame['file']) ? $frame['file'] : "<internal>",
                'lineno' => isset($frame['line']) ? $frame['line'] : 0,
                'method' => $frame['function'],
            ];
        }

        $frames[] = array(
            'filename' => $errfile,
            'lineno' => $errline
        );

        return $frames;
    }
}
