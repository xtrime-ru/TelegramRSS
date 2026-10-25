<?php

namespace TelegramRSS\Tests;

use Amp\File\Driver\BlockingFilesystemDriver;
use Amp\File\FilesystemDriver;
use Amp\File\FilesystemException;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use TelegramRSS\Cache\CacheStatus;
use TelegramRSS\Cache\CacheEntry;
use TelegramRSS\Cache\CacheResult;
use TelegramRSS\Cache\FloodWaitException;
use TelegramRSS\Cache\TelegramCache;
use TelegramRSS\Logger;

use function Amp\File\filesystem;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class CachePersistenceTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private string $file;
    private Clock $clock;

    protected function setUp(): void
    {
        $directory = sys_get_temp_dir() . '/tg-rss-cache-' . bin2hex(random_bytes(6));
        mkdir($directory);
        define('ROOT_DIR', $directory);
        mkdir($directory . '/cache');
        $this->file = $directory . '/cache/telegram-cache.jsonl' . (CacheEntry::compressionAvailable() ? '.zst' : '');
        $this->clock = new Clock();
        filesystem(new BlockingFilesystemDriver());
    }

    protected function tearDown(): void
    {
        foreach (glob(dirname($this->file) . '/*') as $file) {
            unlink($file);
        }
        rmdir(dirname($this->file));
        rmdir(ROOT_DIR);
    }

    public function testRestartPreservesEntitiesAndOriginalFreshnessIncludingDowntime(): void
    {
        $cache = $this->cache();
        $feed = ['Chat' => ['title' => "Привет 世界 👋\n"], 'type' => 'channel', 'bot_api_id' => -1000000000123];
        $this->putInfo($cache, 'test', $feed, 1000, 'api');
        $this->putMessage($cache, 'test', ['id' => 7, 'message' => "original \xFF"], 1000, 'getMessages');
        foreach (['-42', '123', '-1000000000042'] as $peer) {
            $this->putMessage($cache, $peer, ['id' => 7, 'peer' => $peer], 1000, 'history');
            $cache->floodWait($peer, 120);
        }
        $cache->floodWait('@TEST', 120);
        $this->clock->time = 1010;
        $this->info($cache, 'test');
        $cache->save();
        $contents = file_get_contents($this->file);
        self::assertStringContainsString('Привет 世界 👋', CacheEntry::compressionAvailable() ? zstd_uncompress($contents) : $contents);
        $this->clock->time = 1030;
        $restored = $this->cache();
        self::assertSame($feed, $this->info($restored, 'test')->value);
        self::assertSame($feed, $restored->peer('test')->peerInfo('-1000000000123')->unpack());
        self::assertSame(['id' => 7, 'message' => 'original �'], $this->message($restored, 'test', 7)->value);
        foreach (['test', '-42', '123', '-1000000000042'] as $peer) {
            if ($peer !== 'test') {
                self::assertSame(['id' => 7, 'peer' => $peer], $this->message($restored, $peer, 7)->value);
            }
            try {
                $restored->checkCooldown($peer);
                self::fail('Expected persisted cooldown');
            } catch (FloodWaitException $error) {
                self::assertSame(90, $error->retryAfter);
            }
        }
        $this->clock->time = 1060;
        self::assertNull($this->info($restored, 'test'));
        self::assertSame(CacheStatus::STALE, $this->info($restored, 'test', true)->status);
        $restored->save();
        $this->clock->time = 1900;
        $restored = $this->cache();
        self::assertSame(1000.0, $this->info($restored, 'test', true)->loadedAt);
        self::assertSame(0, $restored->stats()->cooldowns);
        $this->clock->time = 1901;
        self::assertNull($this->info($this->cache(), 'test', true));
    }

    public function testRestoreHonorsLruAndNewCapacity(): void
    {
        $cache = $this->cache();
        foreach (['a', 'b', 'c'] as $key) {
            $this->putInfo($cache, $key, $key, 1000, 'history');
        }
        $this->putMessage($cache, 'a', ['id' => 7], 1000, 'getMessages');
        $this->info($cache, 'a');
        $cache->save();
        $restored = $this->cache(maxChannels: 2);
        self::assertNull($this->info($restored, 'b'));
        self::assertSame(['c', 'a'], [$this->info($restored, 'c')->value, $this->info($restored, 'a')->value]);
        self::assertSame(['id' => 7], $this->message($restored, 'a', 7)->value);
        self::assertSame(0, $this->cache(enabled: false)->stats()->channels);
    }

    public function testFailedReplacementKeepsPreviousSnapshotAndRemovesTemporaryFile(): void
    {
        $cache = $this->cache();
        $this->putInfo($cache, 'test', 'original', 1000, 'api');
        $cache->save();
        $this->putInfo($cache, 'test', 'changed', 1000, 'api');
        $real = new BlockingFilesystemDriver();
        $driver = Mockery::mock(FilesystemDriver::class);
        foreach (['openFile', 'getStatus', 'deleteFile'] as $method) {
            $driver->shouldReceive($method)->andReturnUsing($real->$method(...));
        }
        $driver->shouldReceive('move')->once()->andThrow(new FilesystemException('rename failed'));
        filesystem($driver);
        $logger = Mockery::mock(Logger::class);
        $logger->shouldReceive('error')->once();
        (new ReflectionProperty(Logger::class, 'instanse'))->setValue(null, $logger);
        $cache->save();
        self::assertFileDoesNotExist($this->file . '.tmp');
        self::assertSame('original', $this->info($this->cache(), 'test')->value);
    }

    public function testBrokenSnapshotIsLoggedAndDoesNotPreventStartup(): void
    {
        $cache = $this->cache();
        $this->putInfo($cache, 'test', ['type' => 'channel'], 1000, 'api');
        $cache->save();
        $snapshot = file_get_contents($this->file);
        $logger = Mockery::mock(Logger::class);
        $logger->shouldReceive('error')->twice();
        (new ReflectionProperty(Logger::class, 'instanse'))->setValue(null, $logger);
        foreach (["not-zstd\n", substr($snapshot, 0, intdiv(strlen($snapshot), 2))] as $broken) {
            file_put_contents($this->file, $broken);
            self::assertSame(0, $this->cache()->stats()->channels);
        }
    }

    public function testOldSchemaIsIgnoredWithoutReadingOrMigratingItsRecords(): void
    {
        $legacy = json_encode(['historyWindowSize' => 100, 'unit' => 'channel', 'cooldowns' => ['test' => 2000]])
            . "\nremaining old records are not parsed\n";
        file_put_contents($this->file, CacheEntry::compressionAvailable() ? zstd_compress($legacy, 1) : $legacy);
        $logger = Mockery::mock(Logger::class);
        $logger->shouldNotReceive('error');
        $logger->shouldReceive('notice')->once();
        (new ReflectionProperty(Logger::class, 'instanse'))->setValue(null, $logger);
        $cache = $this->cache();
        self::assertEquals(new \TelegramRSS\Cache\CacheStats(0, 0, 0), $cache->stats());
        $this->putMessage($cache, 'test', ['id' => 7], 1000, 'history');
        $cache->save();
        self::assertSame(['id' => 7], $this->message($this->cache(), 'test', 7)->value);
    }

    public function testLargeHistoryStreamsAcrossChunksAndRestoresItsOrder(): void
    {
        $cache = $this->cache();
        $history = ['messages' => [], 'count' => 100,
            'chats' => [['_' => 'channel', 'id' => -1000000000123, 'username' => 'test', 'title' => 'Test']],
            'users' => [['_' => 'user', 'id' => 42, 'first_name' => 'Author']]];
        foreach (range(100, 1) as $id) {
            $history['messages'][] = ['_' => 'message', 'id' => $id, 'message' => 'Привет 👋 ' . bin2hex(random_bytes(2048))];
        }
        $peer = $cache->peer('test');
        $peer->updateHistory('test', $history, 1000, 100);
        $cache->commit('test', $peer);
        $cache->save();
        $restored = $this->cache();
        self::assertSame($history, $restored->history('test', 100)->value);
        self::assertSame($history['messages'][0], $this->message($restored, 'test', 100)->value);
        $this->clock->time += 60;
        self::assertNull($restored->history('test', 10));
        self::assertSame($history, $restored->history('test', 100, useStale: true)->value);
    }

    public function testDisabledCacheDoesNotReadOrWriteSnapshot(): void
    {
        file_put_contents($this->file, 'untouched');
        filesystem(Mockery::mock(FilesystemDriver::class));
        $cache = new TelegramCache(enabled: false);
        $cache->load();
        $cache->save();
        self::assertSame('untouched', file_get_contents($this->file));
    }

    public function testSnapshotModeChangesKeepReadableDataAndReplaceThePreviousFormat(): void
    {
        if (!CacheEntry::compressionAvailable()) {
            // Without the extension, an existing compressed snapshot is left unread.
            file_put_contents($this->file . '.zst', 'compressed snapshot');
            $cache = $this->cache();
            self::assertSame(0, $cache->stats()->channels);
            $this->putMessage($cache, 'test', ['id' => 7], 1000, 'history');
            $cache->save();
            self::assertSame(['id' => 7], $this->message($this->cache(), 'test', 7)->value);
            self::assertFileDoesNotExist($this->file . '.zst');
            return;
        }
        $cache = $this->cache();
        $this->putMessage($cache, 'test', ['id' => 7, 'message' => 'Привет 👋'], 1000, 'history');
        $cache->save();
        $plain = substr($this->file, 0, -4);
        file_put_contents($plain, zstd_uncompress(file_get_contents($this->file)));
        unlink($this->file);
        $this->clock->time += 30;
        $restored = $this->cache();
        self::assertSame(['id' => 7, 'message' => 'Привет 👋'], $this->message($restored, 'test', 7)->value);
        self::assertSame(1000.0, $this->message($restored, 'test', 7)->loadedAt);
        $restored->save();
        self::assertFileExists($this->file);
        self::assertFileDoesNotExist($plain);
    }

    private function putInfo(TelegramCache $cache, string $peer, mixed $value, float $loadedAt, string $source): void
    {
        $state = $cache->peer($peer);
        $state->info = CacheEntry::pack($value, $loadedAt, $source);
        $cache->commit($peer, $state);
    }

    /** @param array{id: int, ...} $value */
    private function putMessage(TelegramCache $cache, string $peer, array $value, float $loadedAt, string $source): void
    {
        $state = $cache->peer($peer);
        $state->messages[$value['id']] = CacheEntry::pack($value, $loadedAt, $source);
        $cache->commit($peer, $state);
    }

    /** @return CacheResult<mixed>|null */
    private function info(TelegramCache $cache, string $peer, bool $stale = false): ?CacheResult
    {
        return $cache->read($cache->peer($peer)->info, $stale);
    }

    /** @return CacheResult<array<string, mixed>>|null */
    private function message(TelegramCache $cache, string $peer, int $id): ?CacheResult
    {
        return $cache->read($cache->peer($peer)->messages[$id] ?? null);
    }

    private function cache(int $maxChannels = 500, bool $enabled = true): TelegramCache
    {
        $cache = new TelegramCache($enabled, $maxChannels, fn () => $this->clock->time);
        $cache->load();
        return $cache;
    }
}
