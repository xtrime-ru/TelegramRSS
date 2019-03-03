<?php

return [
    'url' => (string) getenv('SELF_URL'),
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
    ],
    'media' => [
        'max_size' => (int) getenv('MAX_MEDIA_SIZE'),
    ],
    'ban' => [
        'rpm' => (int) getenv('RPM_LIMIT'),
    ],
    'timezone' => (string) getenv('TIMEZONE'),
    'log' => [
        'dir' => (string) getenv('LOGS_DIR'),
        'file' => (string) getenv('LOGS_FILE'),
    ],
];