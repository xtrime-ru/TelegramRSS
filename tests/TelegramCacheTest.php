<?php

namespace TelegramRSS\Tests;

use Amp\Http\Client\DelegateHttpClient;
use Amp\Http\Client\HttpClient;
use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\Router;
use Amp\Socket\InternetAddress;
use Closure;
use League\Uri\Http;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionProperty;
use Symfony\Bridge\PhpUnit\ClockMock;
use TelegramRSS\AccessControl\AccessControl;
use TelegramRSS\AccessControl\ForbiddenPeers;
use TelegramRSS\AccessControl\User;
use TelegramRSS\Cache\TelegramCache;
use TelegramRSS\Cache\CacheResult;
use TelegramRSS\Cache\CacheStatus;

use TelegramRSS\Config;

use TelegramRSS\Server\AccessLoggerMiddleware;
use TelegramRSS\Server\AuthorizationMiddleware;

use TelegramRSS\TgClient;
use Throwable;
use UnexpectedValueException;

use function Amp\async;

use function Amp\delay;
use function Amp\Future\await;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class TelegramCacheTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function testAuthenticatedQuotaIsSharedAcrossIPsAndLogsAcceptedUsage(): void
    {
        ClockMock::withClockMock(1000);
        $access = $this->accessControlForTest(['reader' => ['password' => 'secret', 'rpm' => 2]]);
        $records = [];
        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('log')->andReturnUsing(function ($level, $message, $context) use (&$records): void {
            $records[] = $context['request'];
        });
        $endpoint = Mockery::mock(RequestHandler::class);
        $endpoint->shouldReceive('handleRequest')->andReturn(new Response(200));
        $handler = Mockery::mock(RequestHandler::class);
        $handler->shouldReceive('handleRequest')->andReturnUsing(fn ($r) => (new AuthorizationMiddleware())->handleRequest($r, $endpoint));
        $middleware = new AccessLoggerMiddleware($logger, $access);
        foreach (['192.0.2.1' => 200, '192.0.2.2' => 200, '192.0.2.3' => 429] as $ip => $status) {
            $request = $this->request(ip: $ip, extra: ['authorization' => 'Basic ' . base64_encode('reader:secret')]);
            self::assertSame($status, $middleware->handleRequest($request, $handler)->getStatus());
        }
        self::assertSame([1, 2, 2], array_column($records, 'rate_limit_used'));
        self::assertSame(['reader', 0, 2], [$records[2]['username'], $records[2]['rate_limit_remaining'], $records[2]['rpm_limit']]);
        self::assertSame(200, $middleware->handleRequest($this->request(ip: '192.0.2.3'), $handler)->getStatus());
    }

    protected function setUp(): void
    {
        define('ROOT_DIR', dirname(__DIR__));
        $GLOBALS['options'] = ['docker' => false];
        foreach ([
            User::class,
            \Symfony\Component\RateLimiter\Policy\SlidingWindowLimiter::class,
            \Symfony\Component\RateLimiter\Storage\InMemoryStorage::class,
        ] as $class) {
            ClockMock::register($class);
        }
        Config::getInstance()->config = include ROOT_DIR . '/config.php';
        Config::getInstance()->config['access']['only_public_channels'] = true;
        Config::getInstance()->config['access']['ip_blacklist'] = '';
        Config::getInstance()->config['access']['forbidden_referer_regex'] = '';
        Config::getInstance()->config['access']['cache_peer_errors_regex'] = '';
        Config::getInstance()->config['server']['real_ip_header'] = 'remote_addr';
        Config::getInstance()->config['url'] = 'https://example.test';
        // Keep peer checks in memory instead of reading the application's persistent blocklist.
        (new ReflectionProperty(ForbiddenPeers::class, 'peers'))->setValue(null, []);
        (new ReflectionProperty(ForbiddenPeers::class, 'regex'))->setValue(null, '');
    }

    /**
     * @param array<array-key, array{rpm?: int, media_rpm?: int, errors_limit?: int, media_errors_limit?: int, password?: string}> $clientsSettings
     */
    private function accessControlForTest(array $clientsSettings = []): AccessControl
    {
        // No persistent-cache I/O and no cleanup timer in regression tests.
        $access = (new ReflectionClass(AccessControl::class))->newInstanceWithoutConstructor();
        foreach (['rpmLimit' => 15, 'mediaRpmLimit' => 50, 'errorsLimit' => 0,
            'mediaErrorsLimit' => 2, 'clientsSettings' => $clientsSettings] as $name => $value) {
            (new ReflectionProperty(AccessControl::class, $name))->setValue($access, $value);
        }
        return $access;
    }

    /**
     * @param array<string, string|list<string>> $extra
     */
    private function request(string $format = 'json', string $channel = 'test', string $query = '', string $ip = '192.0.2.1', array $extra = []): Request
    {
        $path = '/' . $format . '/' . $channel . ($format === 'media' ? '/7' : '');
        $client = Mockery::mock(Client::class);
        $client->shouldReceive('getId')->andReturn(1);
        $client->shouldReceive('getRemoteAddress')->andReturn(new InternetAddress('192.0.2.1', 1000));
        $client->shouldReceive('getLocalAddress')->andReturn(new InternetAddress('127.0.0.1', 9504));
        $request = new Request($client, 'GET', Http::new('https://example.test' . $path . $query), ['remote_addr' => $ip] + $extra);
        $request->setAttribute(Router::class, ['channel' => $channel, 'message_id' => 7]);
        $request->setAttribute('user', new User(1000, 0));
        return $request;
    }

    public function testLeastRecentlyUsedEntryIsEvictedAtTheConfiguredLimit(): void
    {
        $cache = new TelegramCache(maxEntries: 2);
        $load = fn (string $value) => fn () => $this->cacheResult($cache, $value);
        $cache->remember('a', $load('a'));
        $cache->remember('b', $load('b'));
        $cache->remember('a', $load('unexpected'));
        $cache->remember('c', $load('c'));
        self::assertSame('a', $cache->remember('a', $load('unexpected'))->value);
        self::assertSame('reloaded', $cache->remember('b', $load('reloaded'))->value);
    }

    /**
     * @template T
     * @param T $value
     * @return CacheResult<T>
     */
    private function cacheResult(TelegramCache $cache, mixed $value): CacheResult
    {
        return new CacheResult($value, $cache->now(), 'api', CacheStatus::MISS);
    }

    public function testMessagesAreScopedToChannelAndAbsenceFromHistoryDoesNotDeleteThem(): void
    {
        [$clock, , $api, $client] = $this->createClient();
        $client->getHistoryHtml(['peer' => 'test']);
        $client->getMessage('other', 7);
        self::assertSame(1, $api->count('getMessages'));
        $api->handlers['getHistoryHtml'] = fn ($r) => $api->response($r, ['messages' => [['_' => 'messageEmpty', 'id' => 7], ['_' => 'sponsoredMessage', 'id' => 7]]]);
        $client->getHistoryHtml(['peer' => 'empty']);
        $client->getMessage('empty', 7);
        $client->getHistoryHtml(['peer' => 'test', 'add_offset' => 100]);
        self::assertSame('Version 1', $client->getMessage('test', 7)['message']);
        self::assertSame(2, $api->count('getMessages'));
        $clock->time += 60;
        $api->version = 2;
        self::assertSame($api->message(7), $client->getMessage('test', 7));
        self::assertSame(3, $api->count('getMessages'));
    }

    public function testConcurrentMessageRequestsShareOneLoadAndFailuresCanBeRetried(): void
    {
        [, , $api, $client] = $this->createClient();
        $api->handlers['getMessages'] = function ($r) use ($api) {
            delay(0.01);
            return $api->response($r, ['messages' => [$api->message(7)]]);
        };
        $messages = await([async(fn () => $client->getMessage('test', 7)), async(fn () => $client->getMessage('@TEST', 7))]);
        self::assertSame([$api->message(7), $api->message(7)], $messages);
        self::assertSame(1, $api->count('getMessages'));
        $api->handlers['getMessages'] = fn ($r) => $api->error($r, 'CHANNEL_PRIVATE');
        self::assertThrows(fn () => $client->getMessage('test', 9), UnexpectedValueException::class);
        unset($api->handlers['getMessages']);
        self::assertSame(9, $client->getMessage('test', 9)['id']);
    }

    private function createApiClient(ApiFixture $api): HttpClient
    {
        $delegate = Mockery::mock(DelegateHttpClient::class);
        $delegate->shouldReceive('request')->andReturnUsing($api->request(...));
        return new HttpClient($delegate, []);
    }

    /**
     * @return array{Clock, TelegramCache, ApiFixture, TgClient}
     */
    private function createClient(bool $enabled = true): array
    {
        $clock = new Clock();
        $cache = new TelegramCache($enabled, clock: fn () => $clock->time);
        $api = new ApiFixture();
        $client = new TgClient('fake.test', 9503, cache: $cache, httpClient: $this->createApiClient($api));
        return [$clock, $cache, $api, $client];
    }

    /**
     * @template T of Throwable
     * @param Closure(): mixed $callback
     * @param class-string<T> $class
     * @return T
     */
    private static function assertThrows(Closure $callback, string $class): Throwable
    {
        try {
            $callback();
        } catch (Throwable $e) {
            self::assertInstanceOf($class, $e);
            return $e;
        }
        self::fail('Expected exception ' . $class);
    }
}
