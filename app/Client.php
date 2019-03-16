<?php

namespace TelegramRSS;

use \Curl\Curl;

class Client
{
    private const RETRY = 5;
    private const RETRY_INTERVAL = 2;
    private const RETRY_MESSAGE = 'Fatal error. Restarting.';

    private $curl;

    /**
     * Client constructor.
     * @param string $address
     * @param string $port
     * @throws \ErrorException
     */
    public function __construct(string $address = '', string $port = '')
    {

        $config = Config::getInstance()->get('client');
        $config = [
            'address'=> $address ?: $config['address'],
            'port'=> $port ?: $config['port'],
        ];

        $this->curl = new Curl("{$config['address']}:{$config['port']}");

        $process = new \Swoole\Process(function (\Swoole\Process $process) {
            echo PHP_EOL . 'Checking telegram client ...' . PHP_EOL;
            $time = microtime(true);
            try{
                echo 'username: ' . ($this->get_self()->username ?? '-') . PHP_EOL;
            } catch (\Exception $e){
                echo "Check failed: Code: {$e->getCode()}. {$e->getMessage()}" . PHP_EOL;
            }

            $time = round(microtime(true) - $time, 3);
            echo PHP_EOL . "Client started: $time sec" . PHP_EOL;
            $process->exit();
        });

        $process->start();

    }

    /**
     * @param $method
     * @param array $parameters
     * @param int $retry
     * @return object
     * @throws \Exception
     */
    private function get($method, $parameters = [], $retry = 0){
        if ($retry){
            //Делаем попытку реконекта
            sleep(static::RETRY_INTERVAL);
            echo 'Client crashed and restarting. Resending request.' . PHP_EOL;
            Log::getInstance()->add('Client crashed and restarting. Resending request.');
        }

        $this->curl->get("/api/$method", $parameters);

        if ($this->curl->error) {
            $message = $this->curl->response->errors[0]->message ?? '';
            if ((!$message || $message === static::RETRY_MESSAGE) && $retry < static::RETRY) {
                return $this->get($method, $parameters, ++$retry);
            }
            if ($message){
                throw new \UnexpectedValueException($message, $this->curl->response->errors[0]->code ?? 400);
            }
            throw new \UnexpectedValueException('Telegram client connection error', $this->curl->errorCode);
        }

        /** @var \stdClass $result */
        $result = $this->curl->response;
        if (!empty($result->response)) {
            $result = $result->response;
        } else {
            throw new \UnexpectedValueException('Telegram client connection error', $this->curl->errorCode);
        }
        return $result;

    }

    public function getHistory($data) {
        $data = array_merge([
            'peer' =>'',
            'limit' => 10,
        ],$data);
        return $this->get('getHistory', ['data'=>$data]);
    }

    public function get_self() {
        return $this->get('get_self');
    }

    public function getMessage($data){
        $data = array_merge([
            'channel' =>'',
            'id' => [0],
        ],$data);

        return $this->get('channels.getMessages', ['data'=>$data]);
    }

    public function getMedia($data){
        $data = array_merge([
            'channel' =>'',
            'id' => [0],
            'size_limit' => Config::getInstance()->get('media.max_size')
        ],$data);

        return $this->get('getMedia', ['data'=>$data]);
    }

    public function getMediaPreview($data){
        $data = array_merge([
            'channel' =>'',
            'id' => [0],
        ],$data);

        return $this->get('getMediaPreview', ['data'=>$data]);
    }

    public function getMediaInfo(object $message){
        return $this->get('get_download_info', ['message'=>$message]);
    }
}