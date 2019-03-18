<?php
//Check if autoload has been already loaded (in case plugin installed in existing project)
$root = __DIR__;
if (!class_exists('TelegramRSS')) {
    if (!file_exists($root . '/vendor/autoload.php')) {
        $root = __DIR__ . '/../../..';
    }
    if (!file_exists($root . '/vendor/autoload.php')) {
        echo 'Need to run `composer install` before launch' . PHP_EOL;
        exit;
    }
    require_once $root . '/vendor/autoload.php';
    chdir($root);
}
if (!file_exists('.env')) {
    echo 'No .env file found. Making copy of .env.example' . PHP_EOL;
    copy('.env.example', '.env');
}
//Check if root env file hash been loaded (in case plugin installed in existing project)
if (!getenv('SWOOLE_SERVER_ADDRESS')) {
    Dotenv\Dotenv::create($root, '.env')->load();
}

date_default_timezone_set(getenv('TIMEZONE', 'UTC'));