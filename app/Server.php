<?php

namespace TelegramRSS;

use Swoole\Http\Request;
use Swoole\Http\Response;

class Server {
    private $config = [];

    /**
     * Server constructor.
     * @param Client $client
     * @param string $address
     * @param string $port
     */
    public function __construct(Client $client, string $address = '', string $port = '') {
        $this->setConfig(
            [
                'address' => $address,
                'port' => $port,
            ]
        );

        $http_server = new \swoole_http_server(
            $this->config['server']['address'],
            $this->config['server']['port'],
            SWOOLE_BASE //single process
        );

        $http_server->set($this->config['options']);

        $ban = new Ban();
		$counter = 0;
        $http_server->on(
            'request',
            static function (Request $request, Response $response) use ($client, $ban, &$counter) {
                //На каждый запрос должны создаваться новые экземпляры классов парсера и коллбеков,
                //иначе их данные будут в области видимости всех запросов.

                //Телеграм клиент инициализируется 1 раз и используется во всех запросах.
                new Controller($request, $response, $client, $ban);
                if (++$counter % 100 === 0) {
                	gc_collect_cycles();
					$counter = 0;
				}
            }
        );
        echo 'Server started' . PHP_EOL;
        $http_server->start();
    }

    /**
     * Установить конфигурацию для http-сервера
     *
     * @param array $config
     * @return Server
     */
    private function setConfig(array $config = []): self {
        $config = [
            'server' => array_filter($config),
        ];

        foreach (['server', 'options'] as $key) {
            $this->config[$key] = array_merge(
                Config::getInstance()->get("swoole.{$key}", []),
                $config[$key] ?? []
            );
        }

        return $this;
    }

}