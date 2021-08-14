<?php

namespace TelegramRSS;


use Swoole\Coroutine;

class Log {

    /**
     * @var self
     */
    private static $instance;
    private bool $echoLog = true;
    private $dir;
    private $file;


    public static function getInstance() {
        if (null === static::$instance) {
            static::$instance = new static();
        }

        return static::$instance;
    }

    /**
     * is not allowed to call from outside to prevent from creating multiple instances,
     * to use the singleton, you have to obtain the instance from Singleton::getInstance() instead
     */
    private function __construct() {
        $this->dir = Config::getInstance()->get('log.dir');
        $this->file = Config::getInstance()->get('log.file');
        if ($this->file) {
            $this->echoLog = false;
        }
    }


    /**
     * @param $input
     * @return Log
     */
    public function add($input): self {
        $caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1];
        $result = '[' . date('Y-m-d H:i:s') . '] ';
        $result .= "{$caller['class']}{$caller['type']}{$caller['function']}: ";
        switch (gettype($input)) {
            case 'object':
            case 'array':
                $result .= json_encode($input, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                break;
            default:
                $result .= $input;
                break;
        }
        $result .= PHP_EOL;
        $this->send($result);
        return $this;
    }

    /**
     * @param string $text
     * @return Log
     */
    private function send(string $text): self
    {
        if ($this->echoLog) {
            echo $text;
        } else {
            Coroutine::writeFile("{$this->dir}/" . $this->getFilename(), $text, FILE_APPEND | LOCK_EX);
        }

        return $this;
    }

    /**
     * @return string
     */
    private function getFilename(): string
    {
        preg_match_all('/%([a-zA-Z]+)/', $this->file, $dateComponents);
        $fileName = $this->file;
        if (count($dateComponents) === 2) {
            $dateComponents = $dateComponents[1];
        } else {
            return $fileName;
        }

        foreach ($dateComponents as $component) {
            $fileName = str_replace("%{$component}", date($component), $fileName);
        }

        return $fileName;
    }

}