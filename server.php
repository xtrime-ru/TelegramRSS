<?php

if (PHP_SAPI !== 'cli') {
    throw new \RuntimeException('Start in CLI');
}

$longopts = [
    'server-address::', // ip адрес сервера, необязательное значение
    'server-port::',  // порт сервера, необязательное значение
    'client-address::',  // ip телеграм-клиента, необязательное значение
    'client-port::',  // порт телеграм-клиента, необязательное значение
    'docker::', //сгенерировать docker-совместимый .env
    'help', //нужна ли справка?
];
$options = getopt('', $longopts);
$options = [
    'server-address' => $options['server-address'] ?? '',
    'server-port' => (int)($options['server-port'] ?? 0),
    'client-address' => $options['client-address'] ?? '',
    'client-port' => (int)($options['client-port'] ?? 0),
    'docker' => isset($options['docker']),
    'help' => isset($options['help']),
];

if ($options['help']) {
    $help = 'Fast, simple, async php telegram rss/json server (based on Swoole Server)

usage: php server.php [--help] [--server-address=127.0.0.1] [--server-port=9504] [--client-address=127.0.0.1] [--client-port=9503]

Options:
    --help              Show this message
    --server-address    Server address (optional) (default: 127.0.0.1)
    --server-port       Server port (optional) (default: 9504)
    --client-address    Telegram Client address (optional) (default: 127.0.0.1)
    --client-port       Telegram Client port (optional) (default: 9503)


Also all options can be set in .env file (see .env.example)

If you dont have Telegram client install from here: 
https://github.com/xtrime-ru/TelegramSwooleClient

Example:
    php server.php
    
';
    echo $help;
    exit;
}

require_once __DIR__ . '/bootstrap.php';

$client = new \TelegramRSS\Client($options['client-address'], $options['client-port']);
new \TelegramRSS\Server($client, $options['server-address'], $options['server-port']);