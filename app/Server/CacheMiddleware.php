<?php

namespace TelegramRSS\Server;

use Amp\Http\Server\Middleware;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use TelegramRSS\Cache\CacheContext;
use TelegramRSS\Cache\CacheStatus;
use TelegramRSS\Cache\TelegramCache;
use TelegramRSS\Cache\CacheReadMode;
use TelegramRSS\Controller\RequestValidatorMiddleware;
use TelegramRSS\TgClient;
use TelegramRSS\Controller\BaseFeedController;

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
            $response = $requestHandler->handleRequest($request);
            $response->setHeader('Cache-Status', 'TelegramRSS; fwd=bypass; detail=media-stream');
            return $response;
        }
        $context->bypassHistory = BaseFeedController::getHistoryParameters($request)->id !== 0;
        $context->readMode = CacheReadMode::OnFlood;
        $this->validator->validateRemotePeer($request, $context);
        $response = $requestHandler->handleRequest($request);
        $status = match ($context->status) {
            CacheStatus::HIT, CacheStatus::STALE => 'hit; ttl=' . (int) floor($cache->ttlSeconds - ($cache->now() - ($context->loadedAt ?? $cache->now()))),
            CacheStatus::MISS, CacheStatus::COALESCED => 'fwd=miss',
            CacheStatus::BYPASS => 'fwd=bypass',
        };
        $response->setHeader('Cache-Status', 'TelegramRSS; ' . $status);
        return $response;
    }
}
