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
        return $request->getAttribute(Router::class)['page'] ?? 1;
    }

    protected function getLimit(Request $request): int
    {
        $limit = (int)($request->getAttributes()['limit'] ?? self::LIMIT_DEFAULT);
        return max(1, min($limit, self::LIMIT_MAX));
    }

    abstract protected function getHeaders(): array;

    protected function getMessages(string $channel, int $page, int $limit): array
    {
        $isChannel = in_array($this->client->getInfo($channel)['type'], ['channel', 'supergroup']);

        $result = $this->client->getHistoryHtml(
            [
                'peer' => $channel,
                'limit' => $limit,
                'add_offset' => ($page - 1) * $limit,
            ]
        );
        if ($isChannel) {
            $result['sponsored_messages'] = $this->client->getSponsoredMessages($channel);
        }

        return $result;
    }


}