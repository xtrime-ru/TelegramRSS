<?php

namespace TelegramRSS\Controller;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use TelegramRSS\RSS\Messages;
use TelegramRSS\RSS\Feed;

class RSSController extends BaseFeedController implements RequestHandler
{

    public function handleRequest(Request $request): Response
    {
        $response = new Response();
        $response->setHeaders($this->getHeaders());
        $response->setBody($this->getContent($request));

        return $response;
    }

    protected function getHeaders(): array
    {
        return [
            'Content-Type' => 'text/xml;charset=UTF-8',
        ];
    }

    protected function getContent(Request $request): string
    {
        $channel = $request->getAttribute('channel');
        $messages = new Messages(
            $this->getMessages($channel, $this->getPage($request), $this->getLimit($request)),
            $this->client,
            $channel
        );
        $rss = new Feed($messages->get(), $channel, $this->client->getFullInfo($channel));
        return $rss->get();
    }

}