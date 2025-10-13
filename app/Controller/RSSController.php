<?php

namespace TelegramRSS\Controller;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use TelegramRSS\RSS\Messages;
use TelegramRSS\RSS\Feed;

class RSSController extends BaseFeedController implements RequestHandler
{

    private Feed $feed;

    public function handleRequest(Request $request): Response
    {
        $response = new Response();
        $this->feed = $this->getFeed($request);
        $response->setHeaders($this->getHeaders());
        $response->setBody($this->feed->get());

        return $response;
    }

    protected function getHeaders(): array
    {
        return [
            'Content-Type' => 'text/xml;charset=UTF-8',
            'ETag' => sprintf('"%s"', $this->feed->hash),
            'Last-Modified' => date(DATE_RFC7231, $this->feed->latestTimestamp),
        ];
    }

    private function getFeed(Request $request): Feed {
        $channel = $request->getAttribute('channel');
        $messages = new Messages(
            $this->getMessages($channel, $this->getPage($request), $this->getLimit($request), $this->getId($request)),
            $this->client,
            $channel
        );
        return new Feed($messages->get(), $channel, $this->client->getFullInfo($channel));
    }

}