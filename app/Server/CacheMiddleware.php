<?php

namespace TelegramRSS\Server;

use Amp\Http\Server\Middleware;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use TelegramRSS\Cache\CacheContext;
use TelegramRSS\Cache\CacheStatus;
use TelegramRSS\Cache\TelegramCache;
use TelegramRSS\Cache\CacheResult;
use TelegramRSS\Cache\FloodWaitException;
use TelegramRSS\Controller\BaseFeedController;
use TelegramRSS\Controller\RequestValidatorMiddleware;
use TelegramRSS\TgClient;

use function Amp\ByteStream\buffer;

/** Runs after authorization and local peer checks, before any Telegram API calls. */
final class CacheMiddleware implements Middleware
{
    public function __construct(
        private readonly TgClient $client,
        private readonly RequestValidatorMiddleware $validator,
        private readonly string $format,
    ) {
    }

    public function handleRequest(Request $request, RequestHandler $requestHandler): Response
    {
        $cache = $this->client->getCache();
        $channel = TelegramCache::normalizeChannel($request->getAttribute('channel'));
        $context = new CacheContext($cache->enabled ? $cache->ttlSeconds : 0);
        $request->setAttribute(CacheContext::class, $context);

        if ($this->format === 'media') {
            $cache->checkCooldown($channel);
            $this->validator->validateRemotePeer($request, $context);
            return $requestHandler->handleRequest($request);
        }
        $params = BaseFeedController::getHistoryParameters($request);
        $context->cacheResponse = $params['id'] === 0 && $this->client->usesHistoryWindow(BaseFeedController::getHistoryQuery($params));
        $key = 'response:' . $channel . ':' . $this->format . ':' . hash('sha256', json_encode($params, JSON_THROW_ON_ERROR));
        /** @var CacheResult<array{status: int, headers: array<string, list<string>>, body: string}> $result */
        $result = $cache->remember($key, function () use ($request, $requestHandler, $context, $cache, $channel, $key): CacheResult {
            try {
                $cache->checkCooldown($channel);
                $this->validator->validateRemotePeer($request, $context);
                $response = $requestHandler->handleRequest($request);
                if ($response->getStatus() !== 200) {
                    throw new \UnexpectedValueException($response->getReason(), $response->getStatus());
                }
                $value = [
                    'status' => $response->getStatus(),
                    'headers' => $response->getHeaders(),
                    'body' => buffer($response->getBody()),
                ];
                return new CacheResult($value, $context->loadedAt ?? $cache->now(), 'response', $context->cacheResponse ? CacheStatus::MISS : CacheStatus::BYPASS);
            } catch (FloodWaitException $e) {
                // All waiters share the loader's final eligibility decision and original data age.
                return $context->cacheResponse ? ($cache->staleResult($key) ?? throw $e) : throw $e;
            }
        });
        $context->observe($result);
        $response = new Response($result->value['status'], $result->value['headers'], $result->value['body']);
        $status = match ($result->status) {
            CacheStatus::HIT, CacheStatus::STALE => 'hit; ttl=' . (int) floor($cache->ttlSeconds - ($cache->now() - $result->loadedAt)),
            CacheStatus::MISS, CacheStatus::COALESCED => 'fwd=miss',
            CacheStatus::BYPASS => 'fwd=bypass',
        };
        $response->setHeader('Cache-Status', 'TelegramRSS; ' . $status);
        return $response;
    }
}
