<?php
return [
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
