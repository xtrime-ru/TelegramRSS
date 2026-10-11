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

    /**
     * @return array{page: int, limit: int, id: int}
     */
    public static function getHistoryParameters(Request $request): array
    {
        $page = (int)(
            $request->getAttribute(Router::class)['page']
            ?? $request->getQueryParameter('page')
            ?: 1
        );
        return [
            'page' => max(1, $page),
            'limit' => max(1, min((int)($request->getQueryParameter('limit') ?? self::LIMIT_DEFAULT), self::LIMIT_MAX)),
            'id' => (int)($request->getQueryParameter('id') ?? 0),
        ];
    }

    /**
     * @param array{page: int, limit: int, id: int} $params
     * @return array{limit: int, add_offset: int, offset_id: int}
     */
    public static function getHistoryQuery(array $params): array
    {
        return [
            'limit' => $params['limit'],
            'add_offset' => ($params['page'] - 1) * $params['limit'],
            'offset_id' => $params['id'] ? $params['id'] + 1 : 0,
        ];
    }

    /**
     * @return History
     */
    protected function getMessages(Request $request): array
    {
        /** @var string $channel */
        $channel = $request->getAttribute('channel');
        $context = CacheContext::fromRequest($request);
        $isChannel = in_array($this->client->getInfo($channel, context: $context)['type'], ['channel', 'supergroup']);

        $result = $this->client->getHistoryHtml(
            ['peer' => $channel] + self::getHistoryQuery(self::getHistoryParameters($request)),
            $context,
        );
        if ($isChannel) {
            $result['sponsored_messages'] = $this->client->getSponsoredMessages($channel, $context);
        }

        return $result;
    }


}
