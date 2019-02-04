<?php

namespace TelegramRSS;


class Client
{
    private $config;

    /**
     * Client constructor.
     * @param string $address
     * @param string $port
     */
    public function __construct(string $address = '', string $port = '')
    {

        $config = Config::getInstance()->get('client');
        $this->config = [
            'address'=> $address ?: $config['address'],
            'port'=> $port ?: $config['port'],
        ];

        echo PHP_EOL . 'Checking telegram client ...' . PHP_EOL;
        $time = microtime(true);
        //send client request
        $time = round(microtime(true) - $time, 3);
        echo PHP_EOL . "Client started: $time sec" . PHP_EOL;

    }
}