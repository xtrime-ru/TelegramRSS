<?php

namespace TelegramRSS\Cache;

use Amp\DeferredFuture;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use TelegramRSS\Logger;

use function Amp\ByteStream\splitLines;
use function Amp\File\deleteFile;
use function Amp\File\isFile;
use function Amp\File\move;
use function Amp\File\openFile;

/** Shared by all routes of one TelegramRSS process. Only arrays / scalar data are stored. */
final class TelegramCache
{
    private const FILE = '/cache/telegram-cache.jsonl';
    private readonly ArrayAdapter $entries;
    /** @var array<string, DeferredFuture<CacheResult<mixed>>> */
    private array $loading = [];
    /** @var array<string, float> Normalized peer to Unix pause deadline. */
    private array $cooldowns = [];
    /** @var \Closure(): float */
    private readonly \Closure $clock;

    /**
     * @param (\Closure(): float)|null $clock Unix time in seconds.
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
        $this->clock = $clock ?? static fn (): float => microtime(true);
        // ArrayAdapter treats zero as unlimited; writes are disabled separately for maxEntries=0.
        $this->entries = new ArrayAdapter(0, true, 0, max(1, $maxEntries));
    }

    public function save(): void
    {
        if (!$this->enabled) {
            return;
        }
        $path = ROOT_DIR . self::FILE;
        $temporary = $path . '.tmp';
        try {
            $file = openFile($temporary, 'wb');
            try {
                $this->pruneCooldowns();
                $file->write(json_encode([
                    'historyWindowSize' => $this->historyWindowSize,
                    'cooldowns' => $this->cooldowns,
                ], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION) . "\n");
                foreach ($this->entries->getItems(array_keys($this->entries->getValues(true))) as $key => $item) {
                    if ($item->isHit()) {
                        $file->write(json_encode([$key, $item->get()], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION) . "\n");
                    }
                }
            } finally {
                $file->close();
            }
            move($temporary, $path);
        } catch (\Throwable $exception) {
            Logger::getInstance()->error('Failed to save Telegram cache', ['path' => $path, 'exception' => $exception]);
        } finally {
            try {
                if (isFile($temporary)) {
                    deleteFile($temporary);
                }
            } catch (\Throwable $exception) {
                Logger::getInstance()->error('Failed to remove temporary Telegram cache', ['path' => $temporary, 'exception' => $exception]);
            }
        }
    }

    public function load(): void
    {
        if (!$this->enabled) {
            return;
        }
        $path = ROOT_DIR . self::FILE;
        try {
            if (!isFile($path)) {
                return;
            }
            $file = openFile($path, 'rb');
            try {
                $header = null;
                $now = $this->now();
                foreach (splitLines($file) as $line) {
                    $record = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                    if ($header === null) {
                        if (!is_int($record['historyWindowSize'] ?? null) || !is_array($record['cooldowns'] ?? null)) {
                            throw new \UnexpectedValueException('Invalid Telegram cache snapshot');
                        }
                        $header = $record;
                        $this->cooldowns = array_filter($header['cooldowns'], static fn (float $until): bool => $until > $now);
                        continue;
                    }
                    if ($this->maxEntries === 0 || $header['historyWindowSize'] !== $this->historyWindowSize) {
                        continue;
                    }
                    if (!is_array($record) || !array_is_list($record) || count($record) !== 2) {
                        throw new \UnexpectedValueException('Invalid Telegram cache entry');
                    }
                    [$key, $entry] = $record;
                    if (!is_string($key) || !is_array($entry) || !array_is_list($entry) || count($entry) !== 3
                        || !is_numeric($entry[1]) || !is_string($entry[2])) {
                        throw new \UnexpectedValueException('Invalid Telegram cache entry');
                    }
                    if ($entry[1] <= $now && $now - $entry[1] <= max($this->ttlSeconds, $this->staleTtlSeconds)) {
                        $this->entries->save($this->entries->getItem($key)->set($entry));
                    }
                }
                if ($header === null) {
                    throw new \UnexpectedValueException('Empty Telegram cache snapshot');
                }
            } finally {
                $file->close();
            }
        } catch (\Throwable $exception) {
            Logger::getInstance()->error('Failed to load Telegram cache', ['path' => $path, 'exception' => $exception]);
        }
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
    public function get(string $key, \Closure $loader, bool $useStale = false): CacheResult
    {
        if (!$this->enabled) {
            return $loader()->withStatus(CacheStatus::BYPASS);
        }
        /** @var CacheResult<T>|null $cached The key and loader describe the same payload type. */
        $cached = $this->getCached($key, $useStale);
        if ($cached) {
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

    /**
     * @template T
     * @param \Closure(): CacheResult<T> $loader
     * @param (\Closure(): bool)|null $canUseStale Checked after loading, when response eligibility is known.
     * @return CacheResult<T>
     */
    public function getWithStale(string $key, \Closure $loader, ?\Closure $canUseStale = null): CacheResult
    {
        return $this->get($key, function () use ($key, $loader, $canUseStale): CacheResult {
            try {
                return $loader();
            } catch (FloodWaitException $e) {
                /** @var CacheResult<T>|null $stale */
                $stale = $canUseStale === null || $canUseStale() ? $this->staleResult($key) : null;
                return $stale ?? throw $e;
            }
        });
    }

    public function put(string $key, mixed $value, float $loadedAt, string $source): void
    {
        if (!$this->enabled || $this->maxEntries === 0) {
            return;
        }
        $item = $this->entries->getItem(hash('sha256', $key));
        $this->entries->save($item->set([$value, $loadedAt, $source]));
    }

    public function forgetMessage(string $channel, int $id): void
    {
        $this->entries->deleteItem(hash('sha256', self::messageKey($channel, $id)));
    }

    /** @return CacheResult<mixed>|null */
    public function getCached(string $key, bool $useStale = false): ?CacheResult
    {
        if (!$this->enabled || !($cached = $this->raw($key))) {
            return null;
        }
        $age = $this->now() - $cached->loadedAt;
        if ($this->ttlSeconds > 0 && $age < $this->ttlSeconds) {
            return $cached;
        }
        return $useStale && $this->staleTtlSeconds > 0 && $age <= $this->staleTtlSeconds
            ? $cached->withStatus(CacheStatus::STALE)
            : null;
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
        return ['entries' => count($this->entries->getValues(true)), 'loading' => count($this->loading), 'cooldowns' => count($this->cooldowns)];
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
