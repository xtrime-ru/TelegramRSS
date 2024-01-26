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

    protected function getPage(Request $request): int
    {
        $page = (int)(
            $request->getAttribute(Router::class)['page']
            ?? $request->getQueryParameter('page')
            ?: 1
        );
        return max(1, $page);
    }

    protected function getLimit(Request $request): int
    {
        $limit = (int)($request->getQueryParameter('limit') ?? self::LIMIT_DEFAULT);
        return max(1, min($limit, self::LIMIT_MAX));
    }

    protected function getId(Request $request): int
    {
        return (int)($request->getQueryParameter('id') ?? 0);
    }

    abstract protected function getHeaders(): array;

    protected function getMessages(string $channel, int $page, int $limit, int $message_id = 0): array
    {
        $isChannel = in_array($this->client->getInfo($channel)['type'], ['channel', 'supergroup']);

        $result = $this->client->getHistoryHtml(
            [
                'peer' => $channel,
                'limit' => $limit,
                'add_offset' => ($page - 1) * $limit,
                'offset_id' => $message_id ? ($message_id + 1) : 0,
            ]
        );
        if ($isChannel) {
            $result['sponsored_messages'] = $this->client->getSponsoredMessages($channel);
        }

        return $result;
    }


}