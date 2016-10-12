<?php
namespace Artemis;

use Interop\Container\ContainerInterface;

class ArtemisMiddlewareFactory
{
    public function __invoke(ContainerInterface $container)
    {
        $config = $container->has('config')
            ? $container->get('config')
            : [];

        $config = isset($config['artemis'])
            ? $config['artemis']
            : [];

        return new ArtemisMiddleware($config);
    }
}
