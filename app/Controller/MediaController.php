<?php

namespace TelegramRSS\Controller;

use Amp\Http\Server\ClientException;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\Router;
use TelegramRSS\TgClient;
use TelegramRSS\Cache\CacheContext;

class MediaController implements RequestHandler
{

    public function __construct(
        protected TgClient $client
    ) {
    }

    public function handleRequest(Request $request): Response
    {
        $response = $this->client->getMedia(
            ['peer' => $request->getAttribute('channel'), 'id' => [$this->getMessageId($request)]],
            $request->getHeaders(),
            $this->getPreview($request),
            CacheContext::fromRequest($request),
        );

        return new Response($response->getStatus(), $response->getHeaders(), $response->getBody());
    }

    /**
     * @return int|string
     */
    private function getMessageId(Request $request)
    {
        return $request->getAttribute(Router::class)['message_id']
            ?? throw new ClientException($request->getClient(), 'Need to specify message id');
    }

    private function getPreview(Request $request): bool
    {
        return ($request->getAttribute(Router::class)['preview'] ?? '') === 'preview';
    }
}
