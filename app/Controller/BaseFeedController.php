<?php

namespace TelegramRSS\Controller;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Router;
use TelegramRSS\TgClient;

abstract class BaseFeedController implements RequestHandler
{
    private const LIMIT_DEFAULT = 10;
    private const LIMIT_MAX = 100;

    public function __construct(
        protected TgClient $client
    ) {
    }

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

    public static function getHistoryQuery(array $params): array
    {
        return [
            'limit' => $params['limit'],
            'add_offset' => ($params['page'] - 1) * $params['limit'],
            'offset_id' => $params['id'] ? $params['id'] + 1 : 0,
        ];
    }

    protected function getMessages(Request $request): array
    {
        $channel = $request->getAttribute('channel');
        $isChannel = in_array($this->client->getInfo($channel)['type'], ['channel', 'supergroup']);

        $result = $this->client->getHistoryHtml(
            ['peer' => $channel] + self::getHistoryQuery(self::getHistoryParameters($request))
        );
        if ($isChannel) {
            $result['sponsored_messages'] = $this->client->getSponsoredMessages($channel);
        }

        return $result;
    }


}
