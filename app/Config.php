<?php


namespace TelegramRSS;


class Config {
    private static Config $instance;
    /** @var array<string, mixed> */
    public array $config;

    public static function getInstance(): Config
    {
        if (empty(static::$instance)) {
            static::$instance = new static();
        }

        return static::$instance;
    }

    /**
     * is not allowed to call from outside to prevent from creating multiple instances,
     * to use the singleton, you have to obtain the instance from Singleton::getInstance() instead
     */
    private function __construct()
    {
        $this->config = include ROOT_DIR . '/config.php';
    }

    /**
     * @param string $key Dot-separated configuration path.
     * @param mixed $default
     * @return mixed
     */
    public function get($key = '', $default = null)
    {
        return $this->findByKey($key) ?? $default;
    }

    /**
     * @param string $key
     * @return mixed
     */
    private function findByKey($key)
    {
        $key = (string) $key;
        $path = explode('.', $key);

        $value = &$this->config;
        foreach ($path as $pathKey) {
            if (!is_array($value) || !array_key_exists($pathKey, $value)) {
                return null;
            }
            $value = &$value[$pathKey];
        }

        return $value;
    }

}