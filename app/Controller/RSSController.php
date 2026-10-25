<?php

namespace TelegramRSS\Controller;

use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use TelegramRSS\RSS\Messages;
use TelegramRSS\Cache\CacheContext;
use TelegramRSS\RSS\Feed;

class RSSController extends BaseFeedController
{

    public function handleRequest(Request $request): Response
    {
        /** @var string $channel */
        $channel = $request->getAttribute('channel');
        $cancellation = $request->getCancellation();
        $messages = new Messages(
            $this->getMessages($request),
            $this->client,
            $channel,
            $cancellation,
            CacheContext::fromRequest($request),
        );
        $feed = new Feed($messages->get(), $channel, $this->client->getFullInfo($channel, $cancellation, CacheContext::fromRequest($request)));
        return new Response(200, [
            'Content-Type' => 'text/xml;charset=UTF-8',
            'ETag' => sprintf('"%s"', $feed->hash),
            'Last-Modified' => gmdate('D, d M Y H:i:s \\G\\M\\T', $feed->latestTimestamp),
        ], $feed->get());
    }

}
