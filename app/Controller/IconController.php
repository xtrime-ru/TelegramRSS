<?php

namespace TelegramRSS\Controller;

use Amp\Http\HttpStatus;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use TelegramRSS\Server\ErrorResponse;
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
        if ($propicInfo === null) {
            return ErrorResponse::customError(HttpStatus::NOT_FOUND, [
                'code' => HttpStatus::NOT_FOUND,
                'message' => 'Peer has no avatar',
            ]);
        }

        $response = $this->client->downloadToResponse($propicInfo, $request->getHeaders(), $request->getAttribute('channel'));

        return new Response($response->getStatus(), ['x-accel-buffering' => ['no']] + $response->getHeaders(), $response->getBody());
    }
}
