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

if (getenv('SERVER_ADDRESS') === false) {
    throw new RuntimeException(
        'Please update .env or .env.docker. Check .env.example .env.docker.example for changes.'
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
    'server' => [
        'address' => (string) (getenv('SERVER_ADDRESS') ?? '127.0.0.1'),
        'port' => (string) (getenv('SERVER_PORT') ?? '9504'),
        'real_ip_header' => (string)(getenv('REAL_IP_HEADER') ?? ''),
    ],
    'client' => [
        'address' => (string) (getenv('TELEGRAM_CLIENT_ADDRESS') ?? '127.0.0.1'),
        'port' => (string) (getenv('TELEGRAM_CLIENT_PORT') ?? '9503'),
        'username' => (string) (getenv('TELEGRAM_CLIENT_USERNAME')),
        'password' => (string) (getenv('TELEGRAM_CLIENT_PASSWORD')),
    ],
    'access' => [
        'rpm' => (int) getenv('RPM'),
        'errors_limit' => (int) getenv('ERRORS_LIMIT'),
        'media_rpm' => (int) getenv('MEDIA_RPM'),
        'media_errors_limit' => (int) getenv('MEDIA_ERRORS_LIMIT'),
        'clients_settings' => $clientsSettings,
        'only_public_channels' => (bool) getenv('ONLY_PUBLIC_CHANNELS'),
        'forbidden_peer_regex' => (string) getenv('FORBIDDEN_PEER_REGEX'),
        'cache_peer_errors_regex' => (string) getenv('CACHE_PEER_ERRORS_REGEX'),
        'forbidden_referer_regex' => (string)getenv('FORBIDDEN_REFERERS_REGEX'),
        'ip_blacklist' => (string)getenv('IP_BLACKLIST'),
    ],
    'timezone' => (string) getenv('TIMEZONE'),
    'cache' => [
        'enabled' => getenv('CACHE_ENABLED') === false || filter_var(getenv('CACHE_ENABLED'), FILTER_VALIDATE_BOOLEAN),
        'maxEntries' => (int) (getenv('CACHE_MAX_ENTRIES') !== false ? getenv('CACHE_MAX_ENTRIES') : 2000),
        'ttlSeconds' => (int) (getenv('CACHE_TTL_SECONDS') !== false ? getenv('CACHE_TTL_SECONDS') : 60),
        'staleTtlSeconds' => (int) (getenv('CACHE_STALE_TTL_SECONDS') !== false ? getenv('CACHE_STALE_TTL_SECONDS') : 900),
        'historyWindowSize' => (int) (getenv('CACHE_HISTORY_WINDOW_SIZE') !== false ? getenv('CACHE_HISTORY_WINDOW_SIZE') : 100),
    ],
    'log' => [
        'dir' => (string) getenv('LOGS_DIR'),
        'file' => (string) getenv('LOGS_FILE'),
        'level' => (int) getenv('LOGS_LEVEL'),
    ],
];
