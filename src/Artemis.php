<?php
namespace Artemis;

class Artemis
{
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

    public static function init($config = [], $set_exception_handler = true, $set_error_handler = true, $report_fatal_errors = true)
    {
        if (!isset($config['log_dir'])) {
            $config['log_dir'] = 'data/kharon/artemis';
        }

        if (!file_exists($config['log_dir']) || !is_writable($config['log_dir'])) {
            return null;
        }

        self::$instance = new Artemis($config);

        if ($set_exception_handler) {
            set_exception_handler([self::$instance, 'handleException']);
        }
        if ($set_error_handler) {
            set_error_handler([self::$instance, 'handleError']);
        }
        if ($report_fatal_errors) {
            register_shutdown_function([self::$instance, 'handleFatal']);
        }

        return self::$instance;
    }

    private function handleException(\Exception $exception, $extra = null, $payload = null)
    {
        $data = [
            'time' => microtime(true),
        ];

        $data['body']['trace'] = $this->getExceptionTrace($exception, $extra);

        // request, server, person data
        $data['request'] = $this->getRequestData();

        $data['server'] = $this->getServer();
        $data['person'] = $this->getUser();

        if (is_array($payload)) {
            array_merge_recursive($data, $payload);
        }

        $logFile = $this->logDir . '/exception-' . getmypid() . '-' . microtime(true) . '.artemis';
        file_put_contents($logFile, json_encode($data, null, 100));
    }

    private function getExceptionTrace(\Exception $exception, $extra = null)
    {
        $message = $exception->getMessage();

        $frames = [];
        $frames[] = [
            'filename' => $exception->getFile(),
            'lineno' => $exception->getLine()
        ];

        foreach ($exception->getTrace() as $frame) {
            $frames[] = [
                'filename' => @$frame['file'] ?: '<internal>',
                'lineno' => @$frame['line'] ?: 0,
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

    private function getRequestData()
    {
        $request = array(
            'url' => $this->handleUrl($this->getUrl()),
            'user_ip' => $this->getRemoteIp(),
            'headers' => $this->getHeaders(),
            'method' => @$_SERVER['REQUEST_METHOD'] ?: null
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
        $forwarded = @$_SERVER['HTTP_X_FORWARDED_FOR'] ?: null;
        if ($forwarded) {
            $parts = explode(',', $forwarded);
            return $parts[0];
        }
        $realIp = @$_SERVER['HTTP_X_REAL_IP'] ?: null;
        if ($realIp) {
            return $realIp;
        }
        return @$_SERVER['REMOTE_ADDR'] ?: null;
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

}
