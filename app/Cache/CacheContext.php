<?php

namespace TelegramRSS\Cache;

use Amp\Http\Server\Request;

final class CacheContext
{
    public CacheStatus $status = CacheStatus::BYPASS;
    public ?string $messageSource = null;
    public ?float $loadedAt = null;
    public CacheReadMode $readMode = CacheReadMode::Fresh;
    public bool $bypassHistory = false;

    public static function fromRequest(Request $request): ?self
    {
        $context = $request->hasAttribute(self::class) ? $request->getAttribute(self::class) : null;
        return $context instanceof self ? $context : null;
    }

    /**
     * @param CacheResult<mixed> $result
     */
    public function observe(CacheResult $result): void
    {
        $previous = $this->loadedAt === null ? null : $this->status;
        $this->loadedAt = $this->loadedAt === null ? $result->loadedAt : min($this->loadedAt, $result->loadedAt);
        $this->status = match (true) {
            $previous === CacheStatus::BYPASS || $result->status === CacheStatus::BYPASS => CacheStatus::BYPASS,
            $previous === CacheStatus::STALE || $result->status === CacheStatus::STALE => CacheStatus::STALE,
            $previous === CacheStatus::MISS || $result->status === CacheStatus::MISS => CacheStatus::MISS,
            $previous === CacheStatus::COALESCED || $result->status === CacheStatus::COALESCED => CacheStatus::COALESCED,
            default => CacheStatus::HIT,
        };
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
            'cache_ttl' => $this->status === CacheStatus::BYPASS ? 0 : $this->ttl,
        ];
    }
}
