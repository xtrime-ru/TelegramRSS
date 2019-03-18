<?php

require_once __DIR__ . '/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    throw new \RuntimeException('Start in CLI');
}

$shortopts = 's::p::c::o';
$longopts = [
    'server_address::', // ip адрес сервера, необязательное значение
    'server_port::',  // порт сервера, необязательное значение
    'client_address::',  // ip телеграм-клиента, необязательное значение
    'client_port::',  // порт телеграм-клиента, необязательное значение
    'help', //нужна ли справка?
];
$options = getopt($shortopts, $longopts);
$options = [
    'server_address' => $options['server_address'] ?? $options['s'] ?? '',
    'server_port' => $options['port'] ?? $options['p'] ?? '',
    'client_address' => $options['client_address'] ?? $options['c'] ?? '',
    'client_port' => $options['port'] ?? $options['o'] ?? '',
    'help' => isset($options['help']),
];

if ($options['help']) {
    $help = 'Fast, simple, async php telegram server (based on Swoole Server)

usage: php server.php [--help] [-s|--server=127.0.0.1] [-p|--server_port=9504] [-c|--client=127.0.0.1] [-o|--client_port=9503]

Options:
        --help              Show this message
    -s  --server_address    Server address (optional) (example: 127.0.0.1)
    -p  --server_port       Server port (optional) (example: 9504)
    -c  --client_address    Telegram Client address (optional) (example: 127.0.0.1)
    -o  --client_port       Telegram Client port (optional) (example: 9503)


Also all options can be set in .env file (see .env.example)

If you dont have Telegram client install from here: 
https://github.com/xtrime-ru/TelegramSwooleClient

Example:
    php server.php
    
';
    echo $help;
    exit;
}

$client = new \TelegramRSS\Client($options['client_address'], $options['client_port']);
new \TelegramRSS\Server($client, $options['server_address'], $options['server_port']);