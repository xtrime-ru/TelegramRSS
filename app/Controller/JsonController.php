<?php

namespace TelegramRSS\Controller;

use Amp\Http\Server\ClientException;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use JsonException;
use Throwable;

class JsonController extends BaseFeedController implements RequestHandler
{

    /**
     * @throws ClientException
     * @throws Throwable
     * @throws JsonException
     */
    public function handleRequest(Request $request): Response
    {
        $response = new Response();
        $response->setHeaders($this->getHeaders());
        $response->setBody(
            json_encode(
                $this->getMessages(
                    $request->getAttribute('channel'),
                    $this->getPage($request),
                    $this->getLimit($request),
                    $this->getId($request)
                ),
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
            )
        );

        return $response;
    }

    protected function getHeaders(): array
    {
        return [
            'Content-Type' => 'application/json;charset=utf-8',
        ];
    }

}