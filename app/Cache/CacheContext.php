<?php

namespace TelegramRSS\Cache;

use Amp\Http\Server\Request;

final class CacheContext
{
    public CacheStatus $status = CacheStatus::BYPASS;
    public ?string $messageSource = null;
    public ?float $loadedAt = null;
    public bool $cacheResponse = true;

    public static function fromRequest(Request $request): ?self
    {
        $context = $request->hasAttribute(self::class) ? $request->getAttribute(self::class) : null;
        return $context instanceof self ? $context : null;
    }

    public function bypassResponseCache(): void
    {
        $this->cacheResponse = false;
        $this->status = CacheStatus::BYPASS;
    }

    /**
     * @param CacheResult<mixed> $result
     */
    public function observe(CacheResult $result): void
    {
        $this->loadedAt = $this->loadedAt === null ? $result->loadedAt : min($this->loadedAt, $result->loadedAt);
        $this->status = $result->status;
    }

    public function __construct(
        public readonly int $ttl,
    ) {
    }

    /**
     * @return array{cache_status: 'HIT'|'MISS'|'BYPASS'|'STALE'|'COALESCED', message_source: string|null, cache_ttl: int}
     */
    public function logFields(): array
    {
        return [
            'cache_status' => $this->status->value,
            'message_source' => $this->messageSource,
            'cache_ttl' => $this->cacheResponse ? $this->ttl : 0,
        ];
    }
}
