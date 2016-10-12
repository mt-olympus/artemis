<?php
return [
    'artemis' => [
        'enabled' => false,
        'log_dir' => 'data/kharon/artemis',
        'api_key' => '',
    ],
    'dependencies' => [
        'factories' => [
            Artemis\ArtemisMiddleware::class => Artemis\ArtemisMiddlewareFactory::class,
        ],
    ],
    'middleware_pipeline' => [
        'error' => [
            'middleware' => [
                Artemis\ArtemisMiddleware::class,
            ],
            'error' => true,
            'priority' => -9999,
        ],
    ],
];
