<?php

namespace TelegramRSS;

class Controller
{
    /** @var array */
    private $request = [
        'ip' => '',
        'peer' => '',
        'limit' => 10,
        'message'=>0,
        'preview'=>false,
    ];

    private $responseList = [
        'html'=> [
            'type'=> 'html',
            'headers' => [
                ['Content-Type', 'text/html;charset=utf-8'],
            ],
        ],
        'rss'=>[
            'type'=> 'rss',
            'headers' => [
                ['Content-Type', 'application/rss+xml;charset=utf-8'],
            ],
        ],
        'json'=>[
            'type'=> 'json',
            'headers' => [
                ['Content-Type', 'application/json;charset=utf-8'],
            ],
        ],
        'media'=>[
            'type'=>'media',
            'headers' => [],
            'unlink' => true,
        ],
        'favicon.ico'=>[
            'type' => 'favicon.ico',
            'headers' => [
                ['Content-Length', 34494],
                ['Content-Type', 'image/x-icon'],
            ],
        ]
    ];

    /** @var array */
    private $response = [
        'errors' => [],
        'type' => '',
        'headers'=>[],
        'code' => 200,
        'data' => null,
        'file' => null,
    ];

    private $indexPage = __DIR__ . '/../index.html';

    /**
     * Controller constructor.
     * @param \Swoole\Http\Request $request
     * @param \Swoole\Http\Response $response
     * @param Client $client
     */
    public function __construct(\Swoole\Http\Request $request, \Swoole\Http\Response $response, Client $client, Ban $ban)
    {

        Log::getInstance()->add($request);
        //Parse request and generate response

        $this
            ->route($request)
            ->validate($ban)
            ->generateResponse($client, $ban)
            ->checkErrors()
            ->encodeResponse(true, $client)
        ;

        $response->status($this->response['code']);

        foreach ($this->response['headers'] as $header) {
            $response->header(...$header);
        }

        if ($this->response['file']) {
            $response->sendfile($this->response['file']);
            if (!empty($this->response['unlink'])){
                unlink($this->response['file']);
            }

        } else {
            $response->end($this->response['data']);
        }

    }

    /**
     * @param \Swoole\Http\Request $request
     * @return Controller
     */
    private function route(\Swoole\Http\Request $request):self {

        $this->request['ip'] = $request->server['remote_addr'];

        $path = array_values(array_filter(explode('/',  $request->server['request_uri'])));

        switch(true){
            case $path[0]==='favicon.ico':
                $this->response['type'] = $path[0];
                return $this;
            case count($path) < 2:
                $this->response['type'] = 'html';
                return $this;
                break;
        }

        if (array_key_exists($path[0], $this->responseList)) {
            $this->response['type'] = $this->responseList[$path[0]]['type'];
            $this->request['peer'] = urldecode($path[1]);
        } else {
            $this->response['errors'][] = 'Unknown response format';
        }

        if ($this->response['type'] === 'media') {
            $this->request['message'] = (int) ($path[2] ?? 0);
            if (!$this->request['message']) {
                $this->response['errors'][] = 'Unknown message id';
            }
            $this->request['preview'] = ($path[3] ?? '') === 'preview';
        }

        return $this;
    }

    private function validate(Ban $ban = null){

        if (preg_match('/[^\w\-@#]/', $this->request['peer'])){
            $this->response['errors'][] = "WRONG NAME";
        }

        if (preg_match('/bot$/i', $this->request['peer'])){
            $this->response['errors'][] = "BOTS NOT ALLOWED";
        }

        if (preg_match('/[A-Z]/', $this->request['peer'])) {
            $this->response['errors'][] = "UPPERCASE NOT SUPPORTED";
        }

        if ($ban && $this->request['peer']) {
            $timeLeft = $ban->updateIp($this->request['ip'])->timeLeft($this->request['ip']);
            if ($timeLeft) {
                $this->response['errors'][] = "TOO MANY REQUEST / ERRORS. TIME LEFT: {$timeLeft}";
            }
        }

        return $this;
    }

    /**
     * @param Client $client
     * @return Controller
     */
    private function generateResponse(Client $client, Ban $ban):self {

        if ($this->response['errors']) {
            return $this;
        }

        try {
            if ($this->response['type'] === 'media') {
                $data = [
                    'channel' => $this->request['peer'],
                    'id' => [
                        $this->request['message'],
                    ],
                ];
                if ($this->request['preview']){
                    $this->response['data'] = $client->getMediaPreview($data);
                } else {
                    $this->response['data'] = $client->getMedia($data);
                }
            } elseif ($this->request['peer']) {
                $this->response['data'] = $client->getHistory(['peer' => $this->request['peer']]);
                if ($this->response['data']->_ !== 'messages.channelMessages') {
                    throw new \UnexpectedValueException('This is not a channel');
                }
            }

        } catch (\Exception $e) {
            $this->response['errors'][] = [
                'code' => $e->getCode(),
                'message' => $e->getMessage(),
            ];

            if ($ban){
                $ban->addBan($this->request['ip']);
            }
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

        Log::getInstance()->add($this->response['data']);

        return $this;
    }

    /**
     * Кодирует ответ в нужный формат: json
     *
     * @param bool $firstRun
     * @param Client $client
     * @return Controller
     */
    public function encodeResponse($firstRun = true, Client $client): self
    {
        try{
            switch ($this->response['type']) {
                case 'html':
                    $this->response['data'] = file_get_contents($this->indexPage);
                    break;
                case 'json':
                    $this->response['data'] = json_encode(
                        $this->response['data'],
                        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                    );
                    break;
                case 'rss':
                    $url = Config::getInstance()->get('url');
                    $selfLink = "$url/rss/{$this->request['peer']}";
                    $messages = new Messages($this->response['data'], $client);
                    $rss = new RSS($messages->get(), $selfLink);
                    $this->response['data'] = $rss->get();
                    break;
                case 'media':
                    $this->response['file'] = $this->response['data']->file;
                    $this->response['headers'] = $this->response['data']->headers;
                    $this->response['data'] = null;
                    break;
                case 'favicon.ico':
                    $this->response['file'] = __DIR__ . '/../favicon.ico';
                    break;
                default:
                    $this->response['data'] = 'Unknown response type';
            }

            if (!$this->response['headers']) {
                $this->response['headers'] = $this->responseList[$this->response['type']]['headers'];
            }
        } catch (\Exception $e){
            $this->response['errors'][] = [
                'code' => $e->getCode(),
                'message' => $e->getMessage(),
            ];
            if ($firstRun){
                $this->checkErrors()->encodeResponse(false, $client);
            }
        }


        return $this;
    }
}