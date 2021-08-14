<?php

namespace TelegramRSS;

use Exception;
use Swoole\Http\Request;
use Swoole\Http\Response;
use TelegramRSS\AccessControl\AccessControl;
use TelegramRSS\AccessControl\ForbiddenPeers;
use TelegramRSS\AccessControl\User;
use Throwable;
use UnexpectedValueException;

class Controller {
    private const POSTS_MAX_LIMIT = 100;

    private array $request = [
        'ip' => '',
        'peer' => '',
        'limit' => 10,
        'page' => 1,
        'message' => 0,
        'preview' => false,
        'url' => '',
    ];

    private array $responseList = [
        'html' => [
            'type' => 'html',
            'headers' => [
                'Content-Type'=> 'text/html;charset=utf-8',
            ],
        ],
        'rss' => [
            'type' => 'rss',
            'headers' => [
                'Content-Type' => 'text/xml;charset=UTF-8',
            ],
        ],
        'json' => [
            'type' => 'json',
            'headers' => [
                'Content-Type' => 'application/json;charset=utf-8',
            ],
        ],
        'media' => [
            'type' => 'media',
            'headers' => [],
        ],
        'favicon.ico' => [
            'type' => 'favicon.ico',
            'headers' => [
                'Content-Length' => 34494,
                'Content-Type' => 'image/x-icon',
            ],
        ],
    ];

    private array $response = [
        'errors' => [],
        'type' => '',
        'headers' => [],
        'code' => 200,
        'data' => null,
        'file' => null,
    ];

    private AccessControl $accessControl;
    private User $user;

    private string $indexPage = __DIR__ . '/../index.html';

    /**
     * Controller constructor.
     *
     * @param AccessControl $accessControl
     */
    public function __construct(AccessControl $accessControl)
    {
        $this->accessControl = $accessControl;
    }

    /**
     * Parse request and generate response
     *
     * @param Request $request
     * @param Response $response
     * @param Client $client
     */
    public function process(Request $request, Response $response, Client $client) {
        $this
            ->route($request)
            ->validate()
            ->generateResponse($client, $request)
            ->checkErrors()
            ->encodeResponse($client)
        ;

        $response->status($this->response['code']);

        $response->header = $this->response['headers'];

        if ($this->response['file']) {
            $response->sendfile($this->response['file']);
        } else {
            $response->end($this->response['data']);
        }

        $response->close();
    }

    /**
     * @param Request $request
     * @return Controller
     */
    private function route(Request $request): self {
        //nginx proxy pass ?? custom header ?? default value
        $this->request['ip'] = $request->header['x-real-ip']
            ??
            $request->header['remote_addr']
            ??
            $request->server['x-forwarded-for']
            ??
            $request->server['remote_addr']
        ;
        $this->request['url'] = $request->server['request_uri'] ?? $request->server['path_info'] ?? '';
        $path = array_values(array_filter(explode('/', $request->server['request_uri'])));
        $type = $path[0] ?? '';

        if ($type === 'media') {
            $accessType = 'media';
        } else {
            $accessType = 'default';
        }

        $this->user = $this->accessControl->getOrCreateUser($this->request['ip'], $accessType);

        Log::getInstance()->add([
            'remote_addr' => $this->request['ip'],
            'user-agent' => $request->header['user-agent'] ?? null,
            'request_uri' => $this->request['url'],
            'post' => $request->post,
            'get' => $request->get,
            'rpm' => $this->user->rpm,
            'rpm_limit' => $this->user->rpmLimit,
            'errors' => \count($this->user->errors),
            'errors_limit' => $this->user->errorsLimit,
        ]);

        switch (true) {
            case $type === 'favicon.ico':
                $this->response['type'] = $type;
                return $this;
            case count($path) < 2:
                $this->response['type'] = 'html';
                return $this;
        }

        if (array_key_exists($type, $this->responseList)) {
            $this->response['type'] = $this->responseList[$type]['type'];
            $this->request['peer'] = urldecode($path[1]);

            $this->request['page'] = (int)($path[2] ?? $request->get['page'] ?? $request->post['page'] ?? $this->request['page']);
            $this->request['page'] = max(1, $this->request['page']);

            $this->request['limit'] = (int)($request->get['limit'] ?? $request->post['limit'] ?? $this->request['limit']);
            $this->request['limit'] = min($this->request['limit'], static::POSTS_MAX_LIMIT);
        } else {
            $this->response['errors'][] = 'Unknown response format';
        }

        if ($this->response['type'] === 'media') {
            $this->request['message'] = (int)($path[2] ?? 0);
            if (!$this->request['message']) {
                $this->response['errors'][] = 'Unknown message id';
            }
            $this->request['preview'] = ($path[3] ?? '') === 'preview';
        }

        return $this;
    }

    private function validate(): self
    {
        if (Config::getInstance()->get('access.only_public_channels')) {
            if (preg_match('/[^\w\-@]/', $this->request['peer'])) {
                $this->response['code'] = 404;
                $this->response['errors'][] = "WRONG NAME";
            }

            if (preg_match('/bot$/i', $this->request['peer'])) {
                $this->response['code'] = 403;
                $this->response['errors'][] = "BOTS NOT ALLOWED";
                $this->user->addError("BOTS NOT ALLOWED", $this->request['url']);
            }
        }

        if ($this->request['peer']) {

            $error = ForbiddenPeers::check($this->request['peer']);
            if ($error !== null) {
                $this->response['code'] = 403;
                $this->response['errors'][] = $error;
                $this->user->addError($error, $this->request['url']);
            }

            $this->user->addRequest($this->request['url']);

            if ($this->user->isBanned()) {
                $this->response['code'] = 400;
                if ($timeLeft = $this->user->getBanDuration()) {
                    $this->response['errors'][] = "Time to unlock access: {$timeLeft}";
                }
                $this->response['errors'] = array_merge($this->response['errors'], $this->user->errors);
            }
        }

        return $this;
    }

    /**
     * @param Client $client
     *
     * @param Request $request
     *
     * @return Controller
     */
    private function generateResponse(Client $client, Request $request): self
    {

        if ($this->response['errors']) {
            return $this;
        }

        try {
            if ($this->request['peer']) {
                //Make request to refresh cache.
                if (
                    Config::getInstance()->get('access.only_public_channels') &&
                    !in_array($client->getInfo($this->request['peer'])->type, ['channel', 'supergroup'])
                ) {
                    throw new UnexpectedValueException('This is not a public channel', 403);
                }

                if ($this->response['type'] === 'media') {
                    $data = [
                        'peer' => $this->request['peer'],
                        'id' => [
                            $this->request['message'],
                        ],
                    ];

                    if ($this->request['preview']) {
                        try {
                            $this->response['data'] = $client->getMediaPreview($data, $request->header);
                        } catch (\Throwable $e) {
                            $this->response['type'] = 'file';
                            $this->response['file'] = ROOT_DIR . '/no-image.jpg';
                            $this->response['headers'] = [
                                'Content-Length' => filesize($this->response['file']),
                                'Content-Type' => 'image/jpeg',
                            ];
                            $this->response['code'] = 404;
                        }
                    } else {
                        $this->response['data'] = $client->getMedia($data, $request->header);
                    }
                    if (!empty($this->response['data']['headers']['Location'])) {
                        $this->response['type'] = 'redirect';
                    }
                } else {
                    $this->response['data'] = $client->getHistoryHtml(
                        [
                            'peer' => $this->request['peer'],
                            'limit' => $this->request['limit'],
                            'add_offset' => ($this->request['page'] - 1) * $this->request['limit'],
                        ]
                    );
                }
            }




        } catch (Exception $e) {
            $this->response['code'] = $e->getCode() ?: 400;
            $this->response['errors'][] = $e->getMessage();
            if ($e->getMessage() !== Client::CLIENT_UNAVAILABLE_MESSAGE) {
                $this->user->addError($e->getMessage(), $this->request['url']);
            }
            ForbiddenPeers::add($this->request['peer'], $e->getMessage());
        }

        return $this;
    }

    private function checkErrors(): self
    {
        if (!$this->response['errors']) {
            return $this;
        }

        $this->response['type'] = 'json';
        $errorCode = $this->response['code'];
        if ($errorCode < 300 || $errorCode >= 600) {
            $errorCode = 400;
        }
        $this->response['code'] = $errorCode;
        $this->response['data'] = [
            'errors' => $this->response['errors'],
        ];

        Log::getInstance()->add($this->response['data']);

        return $this;
    }

    /**
     * Кодирует ответ в нужный формат: json
     *
     * @param Client $client
     * @param bool $firstRun
     * @return Controller
     */
    public function encodeResponse(Client $client, bool $firstRun = true): self
    {
        try {
            switch ($this->response['type']) {
                case 'html':
                    $this->response['file'] = $this->indexPage;
                    break;
                case 'json':
                    $this->response['data'] = json_encode(
                        $this->response['data'],
                        JSON_THROW_ON_ERROR |
                        JSON_PRETTY_PRINT |
                        JSON_UNESCAPED_SLASHES |
                        JSON_UNESCAPED_UNICODE |
                        JSON_INVALID_UTF8_SUBSTITUTE
                    );
                    break;
                case 'rss':
                    $messages = new Messages($this->response['data'], $client);
                    $rss = new RSS($messages->get(), $this->request['peer']);
                    $this->response['data'] = $rss->get();
                    break;
                case 'media':
                    $this->response['headers'] = $this->response['data']['headers'];
                    $this->response['code'] = $this->response['data']['code'];
                    $this->response['data'] = $this->response['data']['file'];
                    break;
                case 'redirect':
                    $this->response['headers'] = $this->response['data']['headers'];
                    $this->response['data'] = null;
                    $this->response['code'] = 302;
                    break;
                case 'favicon.ico':
                    $this->response['file'] = ROOT_DIR . '/favicon.ico';
                    break;
                case 'file':
                    $this->response['data'] = null;
                    break;
                default:
                    $this->response['data'] = 'Unknown response type';
            }

            if (!$this->response['headers']) {
                $this->response['headers'] = $this->responseList[$this->response['type']]['headers'];
            }
        } catch (Throwable $e) {
            $this->response['code'] = $e->getCode();
            $this->response['errors'][] = $e->getMessage();
            if ($firstRun) {
                $this->checkErrors()->encodeResponse($client, false);
            }
        }

        return $this;
    }
}