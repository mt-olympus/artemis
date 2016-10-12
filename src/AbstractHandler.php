<?php
namespace Artemis;

abstract class AbstractHandler
{
    protected $enabled = true;

    protected $logDir = 'data/kharon/artemis';

    protected $apiKey;

    protected $hideFields = [
        'password'
    ];

    protected $handled = [];

    public function __construct($config)
    {
        $this->enabled = isset($config['enabled']) ? (bool)$config['enabled'] : false;

        $this->logDir = $config['log_dir'];

        if (isset($config['hide_fields'])) {
            $this->hideFields = $config['hide_fields'];
        }

        $this->apiKey = isset($config['api_key']) ? $config['api_key'] : null;
    }

    protected function prepareData()
    {
        $ret = [
            'time' => microtime(true),
        ];
        if (!empty($this->apiKey)) {
            $ret['api_key'] = $this->apiKey;
        }
        return $ret;
    }

    public function handleException($exception, $extra = null, $payload = null)
    {
        $data = $this->prepareData();

        $data['body']['trace'] = $this->getExceptionTrace($exception, $extra);

        $data['request'] = $this->getRequest();
        $data['server'] = $this->getServer();
        $data['user'] = $this->getUser();

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

    protected function errorHandled($errno, $errstr, $errfile, $errline)
    {
        foreach ($this->handled as $handled) {
            if (isset($handled['errno']) && $handled['errno'] == $errno &&
                isset($handled['errstr']) && $handled['errstr'] == $errstr &&
                isset($handled['errfile']) && $handled['errfile'] == $errfile &&
                isset($handled['errline']) && $handled['errline'] == $errline) {
                    return true;
                }
        }
        return false;
    }

    public function handleError($errno, $errstr, $errfile, $errline)
    {
        if (!$this->errorHandled($errno, $errstr, $errfile, $errline)) {
            $this->handled[] = [
                'errno' => $errno,
                'errstr' => $errstr,
                'errfile' => $errfile,
                'errline' => $errline,
            ];
        } else {
            return;
        }

        $data = $this->prepareData();

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

        $data['request'] = $this->getRequest();
        $data['server'] = $this->getServer();
        $data['user'] = $this->getUser();

        $logFile = $this->logDir . '/error-' . getmypid() . '-' . microtime(true) . '.kharon';
        file_put_contents($logFile, json_encode($data, null, 100));
    }

    protected function getExceptionTrace($exception, $extra = null)
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

    abstract protected function getRequest();

    abstract protected function getUrl();

    abstract protected function getRemoteIp();

    abstract protected function getHeaders();

    protected function handleUrl($url)
    {
        $query = parse_url($url, PHP_URL_QUERY);
        if (!$query) {
            return $url;
        }
        $parsed = [];
        parse_str($query, $parsed);
        $params = $this->hideParams($parsed, 'x');
        $new = str_replace($query, http_build_query($params), $url);
        return $new;
    }

    protected function hideParams($params, $token = '*')
    {
        if (!is_array($params)) {
            $params = json_decode($params, true);
        }
        if (!is_array($params)) {
            return $params;
        }
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

    protected function getServer()
    {
        $server = [
            'host' => gethostname(),
        ];
        return $server;
    }

    protected function getUser()
    {
        //TODO
        return null;
    }

    protected function getErrorData($errfile, $errline)
    {
        $frames = [];
        $backtrace = debug_backtrace();
        foreach ($backtrace as $frame) {
            if (isset($frame['file']) && $frame['file'] == __FILE__) {
                continue;
            }

            $frames[] = [
                'filename' => isset($frame['file']) ? $frame['file'] : "<internal>",
                'lineno' => isset($frame['line']) ? $frame['line'] : 0,
                'method' => $frame['function'],
            ];
        }

        if ($errfile != __FILE__) {
            $frames[] = array(
                'filename' => $errfile,
                'lineno' => $errline
            );
        }

        return $frames;
    }
}

