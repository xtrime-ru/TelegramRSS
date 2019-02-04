<?php

return [
    'swoole' => [
        'server' => [
            'address' => (string) (getenv('SWOOLE_SERVER_ADDRESS') ?? '127.0.0.1'),
            'port' => (string) (getenv('SWOOLE_SERVER_PORT') ?? '9504'),
        ],
        'options'=> [
            'worker_num' => (int) (getenv('SWOOLE_WORKER_NUM') ?? 1),
            'http_compression' => (bool) (getenv('SWOOLE_HTTP_COMPRESSION') ?? true),
        ]
    ],
    'client' => [
        'address' => (string) (getenv('TELEGRAM_CLIENT_ADDRESS') ?? '127.0.0.1'),
        'port' => (string) (getenv('TELEGRAM_CLIENT_PORT') ?? '9503'),
    ]
];