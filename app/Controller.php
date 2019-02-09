<?php

namespace TelegramRSS;

class Controller
{
	/** @var array */
	private $request = [
		'peer' => '',
		'limit' => 10,
		'images' => true,
	];

	private $responseList = [
		'html'=> [
			'type'=> 'html',
			'headers' => [
				['Content-Type', 'text/html;charset=utf-8'],
			],
			'data' => null,
		],
		'rss'=>[
			'type'=> 'rss',
			'headers' => [
				['Content-Type', 'application/rss+xml;charset=utf-8'],
			],
			'data' => null,
		],
		'json'=>[
			'type'=> 'json',
			'headers' => [
				['Content-Type', 'application/json;charset=utf-8'],
			],
			'data' => null,
		]
	];

	/** @var array */
	private $response = [
		'errors' => [],
		'type' => '',
		'headers'=>[],
		'code' => 200,
		'data' => null,
	];

	private $indexPage = __DIR__ . '/../index.html';

    public function __construct(\Swoole\Http\Request $request, \Swoole\Http\Response $response, Client $client)
    {

        //Parse request and generate response

	    $this
		    ->parseRequestUri($request)
		    ->generateResponse($client)
		    ->checkErrors()
	        ->encodeResponse()
	    ;


	    foreach ($this->response['headers'] as $header) {
		    $response->header(...$header);
	    }

        $response->status($this->response['code']);
        $response->end($this->response['data']);
    }

	/**
	 * @param \Swoole\Http\Request $request
	 * @return Controller
	 */
    private function parseRequestUri(\Swoole\Http\Request $request):self {

	    $path = array_values(array_filter(explode('/',  $request->server['request_uri'])));

	    if (!is_array($path) || count($path) !== 2) {
		    $this->response['type'] = 'html';
		    return $this;
	    }

	    if (array_key_exists($path[0], $this->responseList)) {
	    	$this->response['type'] = $this->responseList[$path[0]]['type'];
		    $this->request['peer'] = $path[1];
	    } else {
		    $this->response['errors'][] = 'Unknown response format';
	    }

	    return $this;
    }

	/**
	 * @param Client $client
	 * @return Controller
	 */
	private function generateResponse(Client $client):self {

		if ($this->response['errors']) {
			return $this;
		}

		try {
			if ($this->request['peer']) {
				$this->response['data'] = $client->getHistory(['peer' => $this->request['peer']]);
			}
		} catch (\Exception $e) {
			$this->response['errors'][] = [
				'code' => $e->getCode(),
				'message' => $e->getMessage(),
			];
		}

		return $this;
	}

	private function checkErrors():self {

		if (!$this->response['errors']) {
			return $this;
		}

		$this->response['type'] = 'json';
		$this->response['code'] = 400;
		$this->response['data'] = [
			'errors' => $this->response['errors'],
		];

		return $this;
	}

	/**
	 * Кодирует ответ в нужный формат: json
	 *
	 * @return Controller
	 */
	public function encodeResponse(): self
	{
		switch ($this->response['type']) {
			case 'html':
				$result = file_get_contents($this->indexPage);
				break;
			case 'json':
				$result = json_encode(
					$this->response['data'],
					JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
				);
				break;
			case 'rss':
				$result = 'Work In Progress...';
				break;
			default:
				$result = 'Unknown response type';
		}

		$this->response['data'] = $result;
		$this->response['headers'] = $this->responseList[$this->response['type']]['headers'];

		return $this;
	}
}