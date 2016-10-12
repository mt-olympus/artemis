<?php
namespace Artemis;

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use Zend\Stratigility\ErrorMiddlewareInterface;

class ArtemisMiddleware extends AbstractHandler implements ErrorMiddlewareInterface
{
    private $request;
    /**
     * {@inheritDoc}
     * @see \Zend\Stratigility\ErrorMiddlewareInterface::__invoke()
     */
    public function __invoke($error, Request $request, Response $response, callable $out = null)
    {
        if ($this->enabled && ($error instanceof \Exception || $error instanceof \Throwable)) {
            $this->request = $request;
            $this->handleException($error);
        }
        return $out($request, $response, $error);
    }

    protected function getRequest()
    {
        $request = array(
            'url' => $this->handleUrl($this->getUrl()),
            'user_ip' => $this->getRemoteIp(),
            'headers' => $this->getHeaders(),
            'method' => $this->request->getMethod(),
        );

        if (isset($_SESSION) && $_SESSION) {
            $request['session'] = $this->hideParams($_SESSION);
        }

        if ($this->request->getMethod() == 'GET') {
            $request['GET'] = $this->hideParams($this->request->getQueryParams());
            return $request;
        }

        $body = $this->request->getParsedBody();
        if ($body !== null) {
            $request['BODY'] = $this->hideParams($body);
            return $request;
        }

        $request['BODY'] = $this->hideParams($this->request->getBody()->getContents());
        return $request;
    }

    protected function getUrl()
    {
        return $this->request->getUri()->__toString();
    }

    protected function getRemoteIp()
    {
        $forwarded = $this->request->hasHeader('HTTP_X_FORWARDED_FOR') ? $this->request->getHeader('HTTP_X_FORWARDED_FOR')[0] : null;
        if ($forwarded) {
            $parts = explode(',', $forwarded);
            return $parts[0];
        }
        $realIp = $this->request->hasHeader('HTTP_X_REAL_IP') ? $this->request->getHeader('HTTP_X_REAL_IP')[0] : null;
        if ($realIp) {
            return $realIp;
        }
        return $this->request->hasHeader('REMOTE_ADDR') ? $this->request->getHeader('REMOTE_ADDR')[0] : null;
    }

    protected function getHeaders()
    {
        $headers = $this->request->getHeaders();
        $list = [];
        foreach ($headers as $header => $value) {
            if (count($value) == 1) {
                $value = $value[0];
            }
            $list[$header] = $value;
        }
        return $list;
    }

}
