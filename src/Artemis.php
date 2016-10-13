<?php
namespace Artemis;

class Artemis extends AbstractHandler
{
    /** @var Artemis */
    public static $instance = null;

    public static function init($config = [], $useExceptionHandler = true, $useErrorHandler = true, $useFatalErrors = true)
    {
        if (isset($config['enabled']) && $config['enabled'] === false) {
            return null;
        }

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

    protected function getRequest()
    {
        $request = array(
            'url' => $this->handleUrl($this->getUrl()),
            'user_ip' => $this->getRemoteIp(),
            'headers' => $this->getHeaders(),
            'method' => isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : null
        );

        if ($_GET) {
            $request['GET'] = $this->hideParams($_GET);
        }
        if (isset($_SESSION) && $_SESSION) {
            $request['session'] = $this->hideParams($_SESSION);
        }
        if ($_POST) {
            $request['POST'] = $this->hideParams($_POST);
        } else {
            $data = file_get_contents('php://input');
            if (!empty($data)) {
                $data = json_decode($data, true);
                if ($data) {
                    $request['BODY'] = $this->hideParams($data);
                }
            }
        }

        return $request;
    }

    protected function getUrl()
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

    protected function getRemoteIp()
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

    protected function getHeaders()
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

}

