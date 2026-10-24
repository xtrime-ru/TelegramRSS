<?php

namespace TelegramRSS\Cache;

use Amp\Cancellation;
use Amp\NullCancellation;
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
    private readonly ArrayAdapter $channels;
    /** @var array<string, SharedLoad> */
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
        private readonly int $maxChannels = 500,
        ?\Closure $clock = null,
        public readonly int $ttlSeconds = 60,
        private readonly int $staleTtlSeconds = 900,
        public readonly int $historyWindowSize = 10,
    ) {
        if ($maxChannels < 0 || $ttlSeconds < 0 || $staleTtlSeconds < 0 || $historyWindowSize < 1 || $historyWindowSize > 100) {
            throw new \InvalidArgumentException('Cache limits and TTLs must be non-negative; history window must be within 1-100');
        }
        $this->clock = $clock ?? static fn (): float => microtime(true);
        // ArrayAdapter treats zero as unlimited; writes are disabled separately for maxChannels=0.
        $this->channels = new ArrayAdapter(0, true, 0, max(1, $maxChannels));
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
                    'unit' => 'channel',
                    'cooldowns' => $this->cooldowns,
                ], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION) . "\n");
                foreach ($this->channels->getItems(array_keys($this->channels->getValues(true))) as $key => $item) {
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
                        if ($this->maxChannels === 0 || ($header['unit'] ?? null) !== 'channel' || $header['historyWindowSize'] !== $this->historyWindowSize) {
                            return;
                        }
                        continue;
                    }
                    if (!is_array($record) || !array_is_list($record) || count($record) !== 2) {
                        throw new \UnexpectedValueException('Invalid Telegram cache entry');
                    }
                    [$channel, $values] = $record;
                    if (!is_string($channel) || !is_array($values)) {
                        throw new \UnexpectedValueException('Invalid Telegram cache channel');
                    }
                    foreach ($values as $key => $entry) {
                        if (!is_string($key) || !is_array($entry) || !array_is_list($entry) || count($entry) !== 3
                            || !is_numeric($entry[1]) || !is_string($entry[2])) {
                            throw new \UnexpectedValueException('Invalid Telegram cache entry');
                        }
                        if ($entry[1] > $now || $now - $entry[1] > max($this->ttlSeconds, $this->staleTtlSeconds)) {
                            unset($values[$key]);
                        }
                    }
                    if ($values) {
                        $this->channels->save($this->channels->getItem($channel)->set($values));
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
     * @param \Closure(Cancellation): CacheResult<T> $loader
     * @return CacheResult<T>
     */
    public function get(string $key, \Closure $loader, bool $useStale = false, ?Cancellation $cancellation = null): CacheResult
    {
        $cancellation?->throwIfRequested();
        if (!$this->enabled) {
            return $loader($cancellation ?? new NullCancellation)->withStatus(CacheStatus::BYPASS);
        }
        /** @var CacheResult<T>|null $cached The key and loader describe the same payload type. */
        $cached = $this->getCached($key, $useStale);
        if ($cached) {
            return $cached;
        }
        $coalesced = isset($this->loading[$key]);
        $load = $this->loading[$key] ??= new SharedLoad(function (Cancellation $shared) use ($key, $loader): CacheResult {
            $result = $loader($shared);
            $shared->throwIfRequested();
            if ($result->status !== CacheStatus::BYPASS) {
                $this->put($key, $result->value, $result->loadedAt, $result->source);
            }
            return $result;
        });
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

    public function put(string $key, mixed $value, float $loadedAt, string $source): void
    {
        if (!$this->enabled || $this->maxChannels === 0) {
            return;
        }
        $item = $this->channels->getItem(self::channelKey($key));
        $now = $this->now();
        $oldest = $now - max($this->ttlSeconds, $this->staleTtlSeconds);
        /** @var array<string, array{mixed, float, string}> $values */
        $values = array_filter($item->get() ?? [], static fn (array $entry): bool => $entry[1] >= $oldest && $entry[1] <= $now);
        $values[$key] = [$value, $loadedAt, $source];
        $this->channels->save($item->set($values));
    }

    /** @return CacheResult<mixed>|null */
    public function getCached(string $key, bool $useStale = false): ?CacheResult
    {
        if (!$this->enabled) {
            return null;
        }
        /** @var array{mixed, float, string}|null $entry */
        $entry = $this->channels->getItem(self::channelKey($key))->get()[$key] ?? null;
        if ($entry === null) {
            return null;
        }
        $age = $this->now() - $entry[1];
        $fresh = $this->ttlSeconds > 0 && $age < $this->ttlSeconds;
        if (!$fresh && (!$useStale || $this->staleTtlSeconds === 0 || $age > $this->staleTtlSeconds)) {
            return null;
        }
        return new CacheResult($entry[0], $entry[1], $entry[2], $fresh ? CacheStatus::HIT : CacheStatus::STALE);
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
     * @return array{channels: int, loading: int, cooldowns: int}
     */
    public function stats(): array
    {
        $this->pruneCooldowns();
        return ['channels' => count($this->channels->getValues(true)), 'loading' => count($this->loading), 'cooldowns' => count($this->cooldowns)];
    }

    private static function channelKey(string $key): string
    {
        return hash('sha256', self::normalizeChannel(explode(':', $key, 3)[1] ?? $key));
    }
}
