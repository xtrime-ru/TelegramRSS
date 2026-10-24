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
        $this->file = $directory . '/cache/telegram-cache.jsonl';
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

    public function testRestartPreservesPayloadsValidatorsAndOriginalFreshnessIncludingDowntime(): void
    {
        $cache = $this->cache();
        $feed = ['body' => "<rss>Привет</rss>\n", 'headers' => ['etag' => ['"original"'], 'last-modified' => ['Thu, 22 Oct 2026 19:11:21 GMT']]];
        $cache->put('feed', $feed, 1000, 'response');
        $cache->put('message:test:7', ['id' => 7, 'message' => 'original'], 1000, 'getMessages');
        $cache->floodWait('@TEST', 120);
        $this->clock->time = 1010;
        $cache->getCached('feed');
        $cache->save();
        $this->clock->time = 1030;
        $restored = $this->cache();
        self::assertSame($feed, $restored->getCached('feed')->value);
        self::assertSame(['id' => 7, 'message' => 'original'], $restored->getCached('message:test:7')->value);
        try {
            $restored->checkCooldown('test');
            self::fail('Expected persisted cooldown');
        } catch (FloodWaitException $error) {
            self::assertSame(90, $error->retryAfter);
        }
        $this->clock->time = 1060;
        self::assertNull($restored->getCached('feed'));
        self::assertSame(CacheStatus::STALE, $restored->getCached('feed', useStale: true)->status);
        $restored->save();
        $this->clock->time = 1900;
        $restored = $this->cache();
        self::assertSame(1000.0, $restored->getCached('feed', useStale: true)->loadedAt);
        self::assertSame(0, $restored->stats()['cooldowns']);
        $this->clock->time = 1901;
        self::assertNull($this->cache()->getCached('feed', useStale: true));
    }

    public function testRestoreHonorsLruNewCapacityAndHistoryWindowSettings(): void
    {
        $cache = $this->cache();
        foreach (['a', 'b', 'c'] as $key) {
            $cache->put($key, $key, 1000, 'history');
        }
        $cache->put('message:a:7', ['id' => 7], 1000, 'getMessages');
        $cache->getCached('a');
        $cache->save();
        $restored = $this->cache(maxChannels: 2);
        self::assertNull($restored->getCached('b'));
        self::assertSame(['c', 'a'], [$restored->getCached('c')->value, $restored->getCached('a')->value]);
        self::assertSame(['id' => 7], $restored->getCached('message:a:7')->value);
        self::assertSame(0, $this->cache(enabled: false)->stats()['channels']);
        self::assertSame(0, $this->cache(historyWindowSize: 50)->stats()['channels']);
    }

    public function testFailedReplacementKeepsPreviousSnapshotAndRemovesTemporaryFile(): void
    {
        $cache = $this->cache();
        $cache->put('feed', 'original', 1000, 'response');
        $cache->save();
        $cache->put('feed', 'changed', 1000, 'response');
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
        self::assertSame('original', $this->cache()->getCached('feed')->value);
    }

    public function testBrokenSnapshotIsLoggedAndDoesNotPreventStartup(): void
    {
        file_put_contents($this->file, "not-json\n");
        $logger = Mockery::mock(Logger::class);
        $logger->shouldReceive('error')->once();
        (new ReflectionProperty(Logger::class, 'instanse'))->setValue(null, $logger);
        self::assertSame(0, $this->cache()->stats()['channels']);
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

    private function cache(int $maxChannels = 500, bool $enabled = true, int $historyWindowSize = 100): TelegramCache
    {
        $cache = new TelegramCache($enabled, $maxChannels, fn () => $this->clock->time,
            historyWindowSize: $historyWindowSize);
        $cache->load();
        return $cache;
    }
}
