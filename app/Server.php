<?php

namespace TelegramRSS;

use OpenSwoole\Http\Request;
use OpenSwoole\Http\Response;

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

        $http_server = new \OpenSwoole\HTTP\Server(
            $this->config['server']['address'],
            $this->config['server']['port'],
	        \OpenSwoole\Server::SIMPLE_MODE
        );

        $http_server->set($this->config['options']);

        $accessControl = new AccessControl\AccessControl();
		$counter = 0;
        $http_server->on(
            'request',
            static function (Request $request, Response $response) use ($client, $accessControl, &$counter) {
                //На каждый запрос должны создаваться новые экземпляры классов парсера и коллбеков,
                //иначе их данные будут в области видимости всех запросов.

                //Телеграм клиент инициализируется 1 раз и используется во всех запросах.
                (new Controller($accessControl))->process($request, $response, $client);
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