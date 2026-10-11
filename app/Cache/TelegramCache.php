<?php

namespace TelegramRSS\Cache;

use Amp\DeferredFuture;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/** Shared by all routes of one TelegramRSS process. Only arrays / scalar data are stored. */
final class TelegramCache
{
    private readonly ArrayAdapter $entries;
    /** @var array<string, DeferredFuture<CacheResult<mixed>>> */
    private array $loading = [];
    /** @var array<string, float> Normalized peer to monotonic pause deadline. */
    private array $cooldowns = [];
    /** @var \Closure(): float */
    private readonly \Closure $clock;

    /**
     * @param (\Closure(): float)|null $clock
     */
    public function __construct(
        public readonly bool $enabled = true,
        private readonly int $maxEntries = 2000,
        ?\Closure $clock = null,
        public readonly int $ttlSeconds = 60,
        private readonly int $staleTtlSeconds = 900,
        public readonly int $historyWindowSize = 100,
    ) {
        if ($maxEntries < 0 || $ttlSeconds < 0 || $staleTtlSeconds < 0 || $historyWindowSize < 1 || $historyWindowSize > 100) {
            throw new \InvalidArgumentException('Cache limits and TTLs must be non-negative; history window must be within 1-100');
        }
        $this->clock = $clock ?? static fn (): float => hrtime(true) / 1e9;
        // ArrayAdapter treats zero as unlimited; writes are disabled separately for maxEntries=0.
        $this->entries = new ArrayAdapter(0, true, 0, max(1, $maxEntries));
    }

    public function now(): float
    {
        return ($this->clock)();
    }

    /** @return lowercase-string */
    public static function normalizeChannel(string $channel): string
    {
        return mb_strtolower(ltrim($channel, '@'));
    }

    /**
     * @template T
     * @param \Closure(): CacheResult<T> $loader
     * @return CacheResult<T>
     */
    public function remember(string $key, \Closure $loader): CacheResult
    {
        if (!$this->enabled) {
            return $loader()->withStatus(CacheStatus::BYPASS);
        }
        /** @var CacheResult<T>|null $cached The key and loader describe the same payload type. */
        $cached = $this->raw($key);
        if ($cached && $this->ttlSeconds > 0 && $this->now() - $cached->loadedAt < $this->ttlSeconds) {
            return $cached;
        }
        if (isset($this->loading[$key])) {
            /** @var CacheResult<T> $result */
            $result = $this->loading[$key]->getFuture()->await();
            return $result->withStatus(in_array($result->status, [CacheStatus::STALE, CacheStatus::BYPASS], true) ? $result->status : CacheStatus::COALESCED);
        }

        /** @var DeferredFuture<CacheResult<mixed>> $deferred */
        $deferred = new DeferredFuture();
        $this->loading[$key] = $deferred;
        $deferred->getFuture()->ignore();
        try {
            $result = $loader();
            if ($result->status !== CacheStatus::BYPASS) {
                $this->put($key, $result->value, $result->loadedAt, $result->source);
            }
            $deferred->complete($result);
            return $result;
        } catch (\Throwable $e) {
            $deferred->error($e);
            throw $e;
        } finally {
            unset($this->loading[$key]);
        }
    }

    public function put(string $key, mixed $value, float $loadedAt, string $source): void
    {
        if (!$this->enabled || $this->maxEntries === 0) {
            return;
        }
        $item = $this->entries->getItem(hash('sha256', $key));
        $this->entries->save($item->set([$value, $loadedAt, $source]));
    }

    /**
     * @param array<string, mixed> $message
     */
    public function indexMessage(string $channel, array $message, float $loadedAt, string $source): void
    {
        if (!$this->enabled || !in_array($message['_'] ?? '', ['message', 'messageService'], true) || !isset($message['id'])) {
            return;
        }
        $this->put(self::messageKey($channel, (int) $message['id']), $message, $loadedAt, $source);
    }

    public function forgetMessage(string $channel, int $id): void
    {
        $this->entries->deleteItem(hash('sha256', self::messageKey($channel, $id)));
    }

    /**
     * @return CacheResult<mixed>|null
     */
    public function staleResult(string $key): ?CacheResult
    {
        if (!$this->enabled || $this->staleTtlSeconds === 0) {
            return null;
        }
        $cached = $this->raw($key);
        return $cached && $this->now() - $cached->loadedAt <= $this->staleTtlSeconds
            ? $cached->withStatus(CacheStatus::STALE)
            : null;
    }

    public static function messageKey(string $channel, int $id): string
    {
        return 'message:' . self::normalizeChannel($channel) . ':' . $id;
    }

    public function checkCooldown(string $peer): void
    {
        $this->pruneCooldowns();
        $remaining = (int) ceil(($this->cooldowns[self::normalizeChannel($peer)] ?? 0) - $this->now());
        if ($remaining > 0) {
            throw new FloodWaitException($remaining);
        }
    }

    public function floodWait(string $peer, int $seconds): FloodWaitException
    {
        $this->pruneCooldowns();
        $peer = self::normalizeChannel($peer);
        $this->cooldowns[$peer] = max($this->cooldowns[$peer] ?? 0, $this->now() + max(1, $seconds));
        return new FloodWaitException((int) ceil($this->cooldowns[$peer] - $this->now()));
    }

    private function pruneCooldowns(): void
    {
        $now = $this->now();
        foreach ($this->cooldowns as $peer => $until) {
            if ($until <= $now) {
                unset($this->cooldowns[$peer]);
            }
        }
    }

    /**
     * @return array{entries: int, loading: int, cooldowns: int}
     */
    public function stats(): array
    {
        $this->pruneCooldowns();
        return ['entries' => count($this->entries->getValues()), 'loading' => count($this->loading), 'cooldowns' => count($this->cooldowns)];
    }

    /**
     * @return CacheResult<mixed>|null
     */
    private function raw(string $key): ?CacheResult
    {
        $item = $this->entries->getItem(hash('sha256', $key));
        if (!$item->isHit()) {
            return null;
        }
        /** @var array{0: mixed, 1: float, 2: string} $entry */
        $entry = $item->get();
        return new CacheResult($entry[0], $entry[1], $entry[2], CacheStatus::HIT);
    }
}
