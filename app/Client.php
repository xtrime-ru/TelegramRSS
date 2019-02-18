<?php

namespace TelegramRSS;

use \Curl\Curl;

class Client
{
    private $config;

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
        $this->config = [
            'address'=> $address ?: $config['address'],
            'port'=> $port ?: $config['port'],
        ];

	    $this->curl = new Curl();

        echo PHP_EOL . 'Checking telegram client ...' . PHP_EOL;
        $time = microtime(true);
        try {
	        echo 'username: ' . $this->get_self()->username . PHP_EOL;
        } catch (\Exception $e){
        	echo "Check failed: Code: {$e->getCode()}. {$e->getMessage()}" . PHP_EOL;
        }

        $time = round(microtime(true) - $time, 3);
        echo PHP_EOL . "Client started: $time sec" . PHP_EOL;

    }

	/**
	 * @param $method
	 * @param array $parameters
	 * @return object
	 * @throws \Exception
	 */
    private function get($method, $parameters = []){
		$address = "{$this->config['address']}:{$this->config['port']}";
	    $address .= "/api/$method";
	    $this->curl->get($address, $parameters);

	    if ($this->curl->error) {
	    	if (!empty($this->curl->response->errors[0]->message)){
	    		$message = 'Telegram client error: ' . $this->curl->response->errors[0]->message;
			    throw new \UnexpectedValueException($message, $this->curl->response->errors[0]->code ?? 400);
		    } else {
			    throw new \UnexpectedValueException('Telegram client connection error', $this->curl->errorCode);
		    }
	    } else {
	    	/** @var \stdClass $result */
		    $result = $this->curl->response;
	    	if (!empty($result->response)) {
			    $result = $result->response;
		    } else {
			    throw new \UnexpectedValueException('Telegram client connection error', $this->curl->errorCode);
		    }
		    return $result;
	    }
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
}