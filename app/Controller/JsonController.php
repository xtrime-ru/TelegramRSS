<?php

namespace TelegramRSS\Controller;

use Amp\Http\Server\ClientException;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use JsonException;
use Throwable;

class JsonController extends BaseFeedController
{

    /**
     * @throws ClientException
     * @throws Throwable
     * @throws JsonException
     */
    public function handleRequest(Request $request): Response
    {
        return new Response(200, ['Content-Type' => 'application/json;charset=utf-8'],
            json_encode(
                $this->getMessages($request),
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
            )
        );
    }

}
