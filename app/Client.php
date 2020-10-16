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

    public function getHistoryHtml(array $data)
    {
        $data = array_merge(
            [
                'peer' => '',
                'limit' => 10,
            ],
            $data
        );
        return $this->get('getHistoryHtml', ['data' => $data]);
    }

    public function getMedia(array $data, array $headers)
    {
        $data = array_merge(
            [
                'peer' => '',
                'id' => [0],
                'size_limit' => Config::getInstance()->get('media.max_size'),
            ],
            $data
        );

        return $this->get('getMedia', ['data' => $data], $headers, 'media');
    }

    public function getMediaPreview(array $data, array $headers)
    {
        $data = array_merge(
            [
                'peer' => '',
                'id' => [0],
            ],
            $data
        );

        return $this->get('getMediaPreview', ['data' => $data], $headers,'media');
    }

    public function getMediaInfo(object $message)
    {
        return $this->get('getDownloadInfo', ['message' => $message]);
    }

    public function getInfo($peer)
    {
        return $this->get('getInfo', $peer);
    }

    public function getId($chat) {
        return $this->get('getId', [$chat]);
    }

    /**
     * @param string $method
     * @param mixed $parameters
     * @param array $headers
     * @param string $responseType
     * @param int $retry
     *
     * @return object
     * @throws \Exception
     */
    private function get(string $method, $parameters = [], array $headers = [], string $responseType = 'json', $retry = 0)
    {
        if ($retry) {
            //Делаем попытку реконекта
            echo 'Client crashed and restarting. Resending request.' . PHP_EOL;
            Log::getInstance()->add('Client crashed and restarting. Resending request.');
            Coroutine::sleep(static::RETRY_INTERVAL);
        }

        $curl = new \Co\Http\Client($this->config['address'], $this->config['port'], false);
        $curl->setHeaders(array_merge(['content-type' => 'application/json'], $headers));
        $curl->post("/api/$method", json_encode($parameters, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_IGNORE));
        $curl->recv(static::TIMEOUT);
        $curl->close();

        $body = '';
        $errorMessage = '';

        if ($curl->statusCode === 302 && !empty($curl->headers['location'])) {
            $responseType = 'redirect';
        } elseif (strpos($curl->headers['content-type'], 'json') !== false) {
            $responseType = 'json';
        }

        switch ($responseType) {
            case 'json':
                $body = json_decode($curl->body, false);
                $errorMessage = $body->errors[0]->message ?? '';
                break;
            case 'media':
                if (
                    in_array($curl->statusCode, [200,206], true) &&
                    !empty($curl->body) &&
                    !empty($curl->headers['content-length']) &&
                    !empty($curl->headers['content-type'])
                ) {
                    $body = (object)[
                        'response' => [
                            'file' => $curl->body,
                            'headers' => $curl->headers,
                            'code' => $curl->statusCode,
                        ],
                    ];
                }
                break;
            case 'redirect':
                $body = (object)[
                    'response' => [
                        'headers' => [
                            'Location' => $curl->headers['location'],
                        ],
                    ],
                ];
                break;
        }

        if (!in_array($curl->statusCode, [200,206,302], true) || $curl->errCode || $errorMessage) {
            if ((!$errorMessage || $errorMessage === static::RETRY_MESSAGE) && $retry < static::RETRY) {
                return $this->get($method, $parameters, $headers, $responseType, ++$retry);
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