<?php

namespace TelegramRSS\Controller;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use TelegramRSS\TgClient;

class IconController implements RequestHandler
{
    public function __construct(
        protected TgClient $client
    ) {
    }

    public function handleRequest(Request $request): Response
    {

        $propicInfo = $this->client->getPropicInfo($request->getAttribute('channel'));

        $response = $this->client->downloadToResponse($propicInfo, $request->getHeaders());

        return new Response($response->getStatus(), $response->getHeaders(), $response->getBody());
    }
}