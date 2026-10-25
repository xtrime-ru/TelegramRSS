<?php

namespace TelegramRSS\Controller;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Router;
use TelegramRSS\TgClient;
use TelegramRSS\Cache\CacheContext;

/** @phpstan-import-type History from TgClient */
abstract class BaseFeedController implements RequestHandler
{
    private const LIMIT_DEFAULT = 10;
    private const LIMIT_MAX = 100;

    public function __construct(
        protected TgClient $client
    ) {
    }

    public static function getHistoryParameters(Request $request): FeedParameters
    {
        $page = (int)(
            $request->getAttribute(Router::class)['page']
            ?? $request->getQueryParameter('page')
            ?: 1
        );
        return new FeedParameters(
            max(1, $page),
            max(1, min((int)($request->getQueryParameter('limit') ?? self::LIMIT_DEFAULT), self::LIMIT_MAX)),
            (int)($request->getQueryParameter('id') ?? 0),
        );
    }

    /**
     * @return History
     */
    protected function getMessages(Request $request): array
    {
        /** @var string $channel */
        $channel = $request->getAttribute('channel');
        $context = CacheContext::fromRequest($request);
        $cancellation = $request->getCancellation();
        $isChannel = in_array($this->client->getInfo($channel, context: $context, cancellation: $cancellation)['type'], ['channel', 'supergroup']);

        $result = $this->client->getHistoryHtml(
            ['peer' => $channel] + self::getHistoryParameters($request)->toHistoryQuery(),
            $context,
            $cancellation,
        );
        if ($isChannel) {
            $result['sponsored_messages'] = $this->client->getSponsoredMessages($channel, $context, $cancellation);
        }

        return $result;
    }


}
