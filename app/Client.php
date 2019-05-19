<?php

namespace TelegramRSS;

use Swoole\Coroutine;

class Client {
    private const RETRY = 5;
    private const RETRY_INTERVAL = 3;
    private const TIMEOUT = 1;
    private const RETRY_MESSAGE = 'Fatal error. Exit.';

    /**
     * Client constructor.
     * @param string $address
     * @param string $port
     */
    public function __construct(string $address = '', int $port = 0) {
        $this->config = Config::getInstance()->get('client');
        $this->config = [
            'address' => $address ?: $this->config['address'],
            'port' => $port ?: $this->config['port'],
        ];
    }

    /**
     * @param $method
     * @param array $parameters
     * @param int $retry
     * @return object
     * @throws \Exception
     */
    private function get($method, $parameters = [], $retry = 0) {
        if ($retry) {
            //Делаем попытку реконекта
            echo 'Client crashed and restarting. Resending request.' . PHP_EOL;
            Log::getInstance()->add('Client crashed and restarting. Resending request.');
            Coroutine::sleep(static::RETRY_INTERVAL);
        }

        $curl = new \Co\Http\Client($this->config['address'], $this->config['port'], false);
        $curl->post("/api/$method", $parameters);
        $curl->recv(static::TIMEOUT);

        $body = json_decode($curl->body, false);
        $errorMessage = $body->errors[0]->message ?? '';
        if ($curl->statusCode !== 200 || $curl->errCode || !$body || $errorMessage) {
            if ((!$errorMessage || $errorMessage === static::RETRY_MESSAGE) && $retry < static::RETRY) {
                return $this->get($method, $parameters, ++$retry);
            }
            if ($errorMessage) {
                throw new \UnexpectedValueException($errorMessage, $body->errors[0]->code ?? 400);
            }
            throw new \UnexpectedValueException('Telegram client connection error', $curl->statusCode);
        }

        if (!$result = $body->response ?? null) {
            throw new \UnexpectedValueException('Telegram client connection error', $curl->statusCode);
        }
        return $result;

    }

    public function getHistory($data) {
        $data = array_merge(
            [
                'peer' => '',
                'limit' => 10,
            ],
            $data
        );
        return $this->get('getHistory', ['data' => $data]);
    }

    public function get_self() {
        return $this->get('get_self');
    }

    public function getMessage($data) {
        $data = array_merge(
            [
                'channel' => '',
                'id' => [0],
            ],
            $data
        );

        return $this->get('channels.getMessages', ['data' => $data]);
    }

    public function getMedia($data) {
        $data = array_merge(
            [
                'channel' => '',
                'id' => [0],
                'size_limit' => Config::getInstance()->get('media.max_size'),
            ],
            $data
        );

        return $this->get('getMedia', ['data' => $data]);
    }

    public function getMediaPreview($data) {
        $data = array_merge(
            [
                'channel' => '',
                'id' => [0],
            ],
            $data
        );

        return $this->get('getMediaPreview', ['data' => $data]);
    }

    public function getMediaInfo(object $message) {
        return $this->get('get_download_info', ['message' => $message]);
    }
}