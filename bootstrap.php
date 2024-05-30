<?php
//Check if autoload has been already loaded (in case plugin installed in existing project)
use TelegramRSS\Logger;

$root = __DIR__;
if (!class_exists('TelegramRSS')) {
    if (!file_exists($root . '/vendor/autoload.php')) {
        $root = __DIR__ . '/../../..';
    }
    if (!file_exists($root . '/vendor/autoload.php')) {
        system('composer install -o --no-dev');
        $root = __DIR__;
    }
    require_once $root . '/vendor/autoload.php';
    chdir($root);
}

define('ROOT_DIR', $root);

$envPath = '.env';
if ($options['docker']) {
    $envPath .= '.docker';
}
$envPathExample = $envPath . '.example';

if (!is_file($envPath) || filesize($envPath) === 0) {
    echo "No {$envPath} file found. Making copy of {$envPathExample} \r";
    //Dont use copy because of docker symlinks
    $envContent = file_get_contents($envPathExample);
    file_put_contents($envPath, $envContent);
}
//Check if root env file hash been loaded (in case plugin installed in existing project)
if (!getenv('SERVER_ADDRESS')) {
    Dotenv\Dotenv::createImmutable($root, $envPath)->load();
}

if ($memoryLimit = getenv('MEMORY_LIMIT')) {
    ini_set('memory_limit', $memoryLimit);
}

if ($timezone = getenv('TIMEZONE')) {
    date_default_timezone_set($timezone);
}

if (!function_exists('debug')) {
    function debug(string $message, array $context) {
        Logger::getInstance()->debug($message, $context);
    }
}
if (!function_exists('info')) {
    function info(string $message, array $context = []) {
        Logger::getInstance()->info($message, $context);
    }
}
if (!function_exists('notice')) {
    function notice($message, array $context = []) {
        Logger::getInstance()->notice($message, $context);
    }
}
if (!function_exists('warning')) {
    function warning(string $message, array $context = []) {
        Logger::getInstance()->warning($message, $context);
    }
}
if (!function_exists('error')) {
    function error(string $message, array $context = []) {
        Logger::getInstance()->error($message, $context);
    }
}
if (!function_exists('critical')) {
    function critical(string $message, array $context = []) {
        Logger::getInstance()->critical($message, $context);
    }
}
if (!function_exists('alert')) {
    function alert(string $message, array $context = []) {
        Logger::getInstance()->alert($message, $context);
    }
}
if (!function_exists('emergency')) {
    function emergency(string $message, array $context = []) {
        Logger::getInstance()->emergency($message, $context);
    }
}