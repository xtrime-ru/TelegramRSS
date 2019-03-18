<?php

namespace TelegramRSS;


class Log {

    /**
     * @var self
     */
    private static $instance;
    private $needLog = true;
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
        if (!$this->file) {
            $this->needLog = false;
        }
        $this->createDirIfNotExists();
    }

    /**
     * prevent the instance from being cloned (which would create a second instance of it)
     */
    private function __clone() {
    }

    /**
     * prevent from being unserialized (which would create a second instance of it)
     */
    private function __wakeup() {
    }

    /**
     * @param $input
     * @return Log
     */
    public function add($input): self {
        if (!$this->needLog) {
            return $this;
        }
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
        $this->appendToFile($result);
        return $this;
    }

    /**
     * @param string $text
     * @return Log
     */
    private function appendToFile(string $text): self {
        if (!$this->needLog) {
            return $this;
        }
        go(
            function () use ($text) {
                file_put_contents("{$this->dir}/" . $this->getFilename(), $text, FILE_APPEND | LOCK_EX);
            }
        );
        return $this;
    }

    /**
     * @return Log
     */
    private function createDirIfNotExists(): self {
        if (!$this->needLog) {
            return $this;
        }
        if (!is_dir($this->dir)) {
            if (!mkdir($this->dir, 0755, true) && !is_dir($this->dir)) {
                throw new \RuntimeException("Directory {$this->dir} was not created");
            }
        }
        return $this;
    }

    /**
     * @return string
     */
    private function getFilename(): string {
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