<?php
global $options;
if (
    getenv('RPM') === false
    || getenv('ERRORS_LIMIT') === false
    || getenv('CLIENTS_SETTINGS') === false
) {
    throw new RuntimeException(
        'Please update .env or .env.docker. DEFAULT_RPM, DEFAULT_ERRORS_LIMIT, CLIENTS_SETTINGS required.'
    );
}

$clientsSettings = json_decode(getenv("CLIENTS_SETTINGS"), true, 5, JSON_THROW_ON_ERROR);

if ($options['docker'] && isset($clientsSettings['127.0.0.1'])) {
    $localIp = getHostByName(getHostName());
    foreach (range(0, 255) as $ipPart) {
        $ip = preg_replace('/\.\d*$/', ".{$ipPart}", $localIp);
        $clientsSettings[$ip] = &$clientsSettings['127.0.0.1'];
    }
}

return [
    'url' => (string) getenv('SELF_URL'),
    'swoole' => [
        'server' => [
            'address' => (string) (getenv('SWOOLE_SERVER_ADDRESS') ?? '127.0.0.1'),
            'port' => (string) (getenv('SWOOLE_SERVER_PORT') ?? '9504'),
        ],
        'options' => [
            'worker_num' => (int) (getenv('SWOOLE_WORKER_NUM') ?? 1),
            'http_compression' => (bool) (getenv('SWOOLE_HTTP_COMPRESSION') ?? true),
        ],
    ],
    'client' => [
        'address' => (string) (getenv('TELEGRAM_CLIENT_ADDRESS') ?? '127.0.0.1'),
        'port' => (string) (getenv('TELEGRAM_CLIENT_PORT') ?? '9503'),
    ],
    'media' => [
        'max_size' => (int) getenv('MAX_MEDIA_SIZE'),
    ],
    'access' => [
        'rpm' => (int) getenv('RPM'),
        'errors_limit' => (int) getenv('ERRORS_LIMIT'),
        'media_rpm' => (int) getenv('MEDIA_RPM'),
        'media_errors_limit' => (int) getenv('MEDIA_ERRORS_LIMIT'),
        'clients_settings' => $clientsSettings,
        'only_public_channels' => (bool) getenv('ONLY_PUBLIC_CHANNELS'),
    ],
    'timezone' => (string) getenv('TIMEZONE'),
    'log' => [
        'dir' => (string) getenv('LOGS_DIR'),
        'file' => (string) getenv('LOGS_FILE'),
    ],
];