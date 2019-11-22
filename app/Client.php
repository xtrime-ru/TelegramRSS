<?php

namespace TelegramRSS;

use Swoole\Coroutine;

class Client
{
    private const RETRY = 5;
    private const RETRY_INTERVAL = 3;
    private const TIMEOUT = 1;
    private const RETRY_MESSAGE = 'Fatal error. Exit.';

    /**
     * Client constructor.
     *
     * @param string $address
     * @param int $port
     */
    public function __construct(string $address = '', int $port = 0)
    {
        $this->config = Config::getInstance()->get('client');
        $this->config = [
            'address' => $address ?: $this->config['address'],
            'port' => $port ?: $this->config['port'],
        ];
    }

    public function getHistory(array $data)
    {
        $data = array_merge(
            [
                'peer' => '',
                'limit' => 10,
            ],
            $data
        );
        return $this->get('getHistory', ['data' => $data]);
    }

    public function getMedia(array $data)
    {
        $data = array_merge(
            [
                'peer' => '',
                'id' => [0],
                'size_limit' => Config::getInstance()->get('media.max_size'),
            ],
            $data
        );

        return $this->get('getMedia', ['data' => $data], 'media');
    }

    public function getMediaPreview(array $data)
    {
        $data = array_merge(
            [
                'peer' => '',
                'id' => [0],
            ],
            $data
        );

        return $this->get('getMediaPreview', ['data' => $data], 'media');
    }

    public function getMediaInfo(object $message)
    {
        return $this->get('getDownloadInfo', ['message' => $message]);
    }

    public function getInfo($peer)
    {
        return $this->get('getInfo', $peer);
    }

    /**
     * @param string $method
     * @param mixed $parameters
     * @param string $responseType
     * @param int $retry
     *
     * @return object
     * @throws \Exception
     */
    private function get(string $method, $parameters = [], string $responseType = 'json', $retry = 0)
    {
        if ($retry) {
            //Делаем попытку реконекта
            echo 'Client crashed and restarting. Resending request.' . PHP_EOL;
            Log::getInstance()->add('Client crashed and restarting. Resending request.');
            Coroutine::sleep(static::RETRY_INTERVAL);
        }

        $curl = new \Co\Http\Client($this->config['address'], $this->config['port'], false);
        $curl->setHeaders(['Content-Type' => 'application/json']);
        $curl->post("/api/$method", json_encode($parameters, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_IGNORE));
        $curl->recv(static::TIMEOUT);

        $body = '';
        $errorMessage = '';

        if (strpos($curl->headers['content-type'], 'json') !== false) {
            $responseType = 'json';
        }

        switch ($responseType) {
            case 'json':
                $body = json_decode($curl->body, false);
                $errorMessage = $body->errors[0]->message ?? '';
                break;
            case 'media':
                if ($curl->statusCode === 200 && $curl->body) {
                    $body = (object)[
                        'response' => [
                            'file' => $curl->body,
                            'headers' => [
                                'Content-Length' => $curl->headers['content-length'],
                                'Content-Type' => $curl->headers['content-type'],
                            ],
                        ],
                    ];
                }
                break;
        }

        if ($curl->statusCode !== 200 || $curl->errCode || !$body || !$curl->body || $errorMessage) {
            if ((!$errorMessage || $errorMessage === static::RETRY_MESSAGE) && $retry < static::RETRY) {
                return $this->get($method, $parameters, $responseType, ++$retry);
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
}