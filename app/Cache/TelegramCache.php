<?php

namespace TelegramRSS\Cache;

use Amp\Cancellation;
use Amp\NullCancellation;
use TelegramRSS\Logger;

use function Amp\File\isFile;

/** Shared by all routes of one TelegramRSS process. Only arrays / scalar data are stored. */
final class TelegramCache
{
    use JsonlFileStorage;

    private const FILE = '/cache/telegram-cache.jsonl';
    /** @var MemoryCache<PeerCache> */
    private MemoryCache $channels;
    /** @var array<string, SharedLoad<mixed>> */
    private array $loading = [];
    /** @var array<array-key, float> Normalized peer to Unix pause deadline. */
    private array $cooldowns = [];
    /** @var \Closure(): float */
    private readonly \Closure $clock;

    /**
     * @param (\Closure(): float)|null $clock Unix time in seconds.
     */
    public function __construct(
        public readonly bool $enabled = true,
        private readonly int $maxChannels = 500,
        ?\Closure $clock = null,
        public readonly int $ttlSeconds = 60,
        private readonly int $staleTtlSeconds = 900,
    ) {
        if ($maxChannels < 0 || $ttlSeconds < 0 || $staleTtlSeconds < 0) {
            throw new \InvalidArgumentException('Cache limits and TTLs must be non-negative');
        }
        $this->clock = $clock ?? static fn (): float => microtime(true);
        $this->channels = new MemoryCache($maxChannels);
    }

    public function save(): void
    {
        if (!$this->enabled) {
            return;
        }
        $records = (function (): \Generator {
            $this->pruneCooldowns();
            yield ['version' => 3, 'cooldowns' => $this->cooldowns];
            foreach ($this->channels->getValues() as $peer => $channel) {
                $channel = clone $channel;
                $channel->prune($this->now() - max($this->ttlSeconds, $this->staleTtlSeconds));
                foreach ($channel->entries() as $key => $entry) {
                    $value = $entry->unpack();
                    if ($key === 'history') {
                        $value = ['ids' => $channel->historyIds, 'limit' => $channel->historyLimit, 'complete' => $channel->historyComplete, 'chats' => $channel->historyChatIds, 'users' => $channel->historyUserIds, 'infoId' => $channel->infoId, 'data' => $value];
                    }
                    yield [(string) $peer, $key, $entry->loadedAt, $entry->source, $value];
                }
            }
            yield ['end' => true];
        })();
        $compressed = CacheEntry::compressionAvailable();
        $path = ROOT_DIR . self::FILE . ($compressed ? '.zst' : '');
        if ($this->writeCacheFile($path, $records, compressed: $compressed)) {
            $this->removeCacheFile(ROOT_DIR . self::FILE . ($compressed ? '' : '.zst'));
        }
    }

    public function load(): void
    {
        if (!$this->enabled) {
            return;
        }
        try {
            $path = ROOT_DIR . self::FILE;
            if (CacheEntry::compressionAvailable() && isFile($path . '.zst')) {
                $path .= '.zst';
            }
            if (!isFile($path)) {
                return;
            }
            /** @var MemoryCache<PeerCache> $restored */
            $restored = new MemoryCache($this->maxChannels);
            $cooldowns = null;
            $complete = false;
            $now = $this->now();
            foreach ($this->readCacheLines($path, compressed: str_ends_with($path, '.zst')) as $line) {
                $record = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                if ($complete) {
                    throw new \UnexpectedValueException('Unexpected data after cache end');
                }
                if ($cooldowns === null) {
                    if (($record['version'] ?? null) !== 3) {
                        Logger::getInstance()->notice('Ignoring old Telegram cache format', ['path' => $path, 'version' => $record['version'] ?? null]);
                        return;
                    }
                    if (!is_array($record['cooldowns'] ?? null)) {
                        throw new \UnexpectedValueException('Invalid Telegram cache snapshot');
                    }
                    $cooldowns = array_filter($record['cooldowns'], static fn (float $until): bool => $until > $now);
                    continue;
                }
                if ($record === ['end' => true]) {
                    $complete = true;
                    continue;
                }
                if (!is_array($record) || !array_is_list($record) || count($record) !== 5
                    || !is_string($record[0]) || !is_string($record[1])
                    || !is_numeric($record[2]) || !is_string($record[3])) {
                    throw new \UnexpectedValueException('Invalid Telegram cache entry');
                }
                [$peer, $key, $loadedAt, $source, $value] = $record;
                if ($this->maxChannels === 0 || $loadedAt > $now || $now - $loadedAt > max($this->ttlSeconds, $this->staleTtlSeconds)) {
                    continue;
                }
                $channel = $restored->getValue($peer) ?? new PeerCache();
                if ($key === 'history') {
                    $channel->historyIds = $value['ids'];
                    $channel->historyLimit = $value['limit'];
                    $channel->historyComplete = $value['complete'];
                    $channel->historyChatIds = $value['chats'];
                    $channel->historyUserIds = $value['users'];
                    $channel->infoId = $value['infoId'];
                    $value = $value['data'];
                } elseif ($key === 'info') {
                    $channel->infoId = $value['bot_api_id'] ?? $channel->infoId;
                }
                $channel->restore($key, CacheEntry::pack($value, (float) $loadedAt, $source));
                $restored->setValue($peer, $channel);
            }
            if (!$complete) {
                throw new \UnexpectedValueException('Incomplete Telegram cache snapshot');
            }
            $this->channels = $restored;
            $this->cooldowns = $cooldowns;
        } catch (\Throwable $exception) {
            Logger::getInstance()->error('Failed to load Telegram cache', ['exception' => $exception]);
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
     * @param \Closure(bool): ?CacheResult<T> $read
     * @param \Closure(Cancellation): CacheResult<T> $loader
     * @return CacheResult<T>
     */
    public function resolve(\Closure $read, \Closure $loader, CacheReadMode $mode = CacheReadMode::Fresh, ?Cancellation $cancellation = null): CacheResult
    {
        $cancellation?->throwIfRequested();
        if ($this->enabled && ($cached = $read($mode === CacheReadMode::Stale))) {
            return $cached;
        }
        try {
            $result = $loader($cancellation ?? new NullCancellation);
            return $this->enabled ? $result : $result->withStatus(CacheStatus::BYPASS);
        } catch (FloodWaitException $exception) {
            return $this->enabled && $mode === CacheReadMode::OnFlood
                ? ($read(true)?->withStatus(CacheStatus::STALE) ?? throw $exception)
                : throw $exception;
        }
    }

    public function peer(string $peer): PeerCache
    {
        return $this->enabled ? ($this->channels->getValue(self::normalizeChannel($peer)) ?? new PeerCache()) : new PeerCache();
    }

    public function commit(string $peer, PeerCache $data): void
    {
        if (!$this->enabled || $this->maxChannels === 0) {
            return;
        }
        $data->prune($this->now() - max($this->ttlSeconds, $this->staleTtlSeconds));
        $this->channels->setValue(self::normalizeChannel($peer), $data);
    }

    /**
     * @template T
     * @param CacheEntry<T>|null $entry
     * @return CacheResult<T>|null
     */
    public function read(?CacheEntry $entry, bool $useStale = false): ?CacheResult
    {
        if (!$this->enabled || !$entry || ($status = $this->entryStatus($entry, $useStale)) === null) {
            return null;
        }
        return new CacheResult($entry->unpack(), $entry->loadedAt, $entry->source, $status);
    }

    /**
     * @template T
     * @param \Closure(Cancellation): CacheResult<T> $loader
     * @return CacheResult<T>
     */
    public function fetch(string $key, \Closure $loader, ?Cancellation $cancellation = null): CacheResult
    {
        $cancellation?->throwIfRequested();
        $coalesced = isset($this->loading[$key]);
        /** @var SharedLoad<T> $load The key identifies a single loader/result contract. */
        $load = $this->loading[$key] ??= new SharedLoad($loader);
        try {
            $result = $load->await($cancellation);
        } finally {
            if ($load->isFinished() && ($this->loading[$key] ?? null) === $load) {
                unset($this->loading[$key]);
            }
        }
        return $coalesced && !in_array($result->status, [CacheStatus::STALE, CacheStatus::BYPASS], true)
            ? $result->withStatus(CacheStatus::COALESCED) : $result;
    }

    /**
     * Check requested positions and ages before decompressing any payload.
     * @return CacheResult<array<string, mixed>>|null
     */
    public function history(string $peer, int $limit, int $offset = 0, bool $useStale = false): ?CacheResult
    {
        if (!$this->enabled) {
            return null;
        }
        $channel = $this->peer($peer);
        $entry = $channel->history;
        if (!$entry || ($status = $this->entryStatus($entry, $useStale)) === null) {
            return null;
        }
        if ($offset + $limit > count($channel->historyIds) && !$channel->historyComplete) {
            return null;
        }
        $ids = array_slice($channel->historyIds, $offset, $limit, true);
        $loadedAt = $entry->loadedAt;
        foreach ($ids as $id) {
            if ($id === null) {
                continue;
            }
            $message = $channel->messages[$id] ?? null;
            // getMessages returns raw text; only getHistoryHtml supplies the feed's HTML format.
            if (!$message || $message->source !== 'history' || ($messageStatus = $this->entryStatus($message, $useStale)) === null) {
                return null;
            }
            $loadedAt = min($loadedAt, $message->loadedAt);
            if ($messageStatus === CacheStatus::STALE) {
                $status = CacheStatus::STALE;
            }
        }
        $history = $entry->unpack();
        $messages = [];
        foreach ($ids as $position => $id) {
            $messages[] = $id === null ? $history['messages'][$position] : $channel->messages[$id]->unpack();
        }
        $history['messages'] = $messages;
        foreach (['chats' => $channel->historyChatIds, 'users' => $channel->historyUserIds] as $field => $refs) {
            if (!array_key_exists($field, $history)) {
                continue;
            }
            $entities = [];
            foreach ($refs as $position => $id) {
                $info = $id === null ? null : $channel->peerInfo((string) $id)?->unpack();
                $entity = $id === null ? ($history[$field][$position] ?? null) : ($info[$field === 'users' ? 'User' : 'Chat'] ?? null);
                if ($entity !== null) {
                    $entities[] = $entity;
                }
            }
            $history[$field] = $entities;
        }
        return new CacheResult($history, $loadedAt, 'history', $status);
    }

    public function hasHistoryRange(string $peer, int $offset, int $limit): bool
    {
        $channel = $this->peer($peer);
        return $offset + $limit <= $channel->historyLimit;
    }

    /** @param CacheEntry<mixed> $entry */
    private function entryStatus(CacheEntry $entry, bool $useStale): ?CacheStatus
    {
        $age = $this->now() - $entry->loadedAt;
        if ($age < 0) {
            return null;
        }
        if ($this->ttlSeconds > 0 && $age < $this->ttlSeconds) {
            return CacheStatus::HIT;
        }
        return $useStale && $this->staleTtlSeconds > 0 && $age <= $this->staleTtlSeconds ? CacheStatus::STALE : null;
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

    public function stats(): CacheStats
    {
        $this->pruneCooldowns();
        return new CacheStats(count($this->channels->getValues()), count($this->loading), count($this->cooldowns));
    }

}
