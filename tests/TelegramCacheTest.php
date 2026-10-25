<?php

namespace TelegramRSS\Tests;

use Amp\DeferredFuture;
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
use TelegramRSS\Cache\CacheContext;
use TelegramRSS\Cache\CacheEntry;
use TelegramRSS\Cache\CacheReadMode;
use TelegramRSS\Cache\TelegramCache;
use TelegramRSS\Cache\CacheResult;
use TelegramRSS\Cache\CacheStatus;
use TelegramRSS\Cache\FloodWaitException;
use TelegramRSS\Config;
use TelegramRSS\Controller\JsonController;
use TelegramRSS\Controller\MediaController;
use TelegramRSS\Controller\RSSController;
use TelegramRSS\Controller\RequestValidatorMiddleware;
use TelegramRSS\Server\AccessLoggerMiddleware;
use TelegramRSS\Server\AuthorizationMiddleware;
use TelegramRSS\Server\CacheMiddleware;
use TelegramRSS\TgClient;
use Throwable;
use UnexpectedValueException;

use function Amp\async;
use function Amp\ByteStream\buffer;
use function Amp\delay;
use function Amp\Future\await;
use function Amp\Http\Server\Middleware\stackMiddleware;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class TelegramCacheTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function testPendingLargerHistoryServesSmallerReadersAndRetriesOnlyIfTheReplyIsPartial(): void
    {
        foreach ([[50, 10, false], [50, 10, true], [100, 30, false]] as [$limit, $smaller, $cancelOwner]) {
            [, , $api, $client] = $this->createClient();
            /** @var DeferredFuture<void> $started */
            $started = new DeferredFuture();
            /** @var DeferredFuture<void> $resume */
            $resume = new DeferredFuture();
            $api->handlers['getHistoryHtml'] = function ($r, $p) use ($api, $started, $resume, $limit) {
                $query = $p['data'];
                if ($query['limit'] === $limit) {
                    $started->complete();
                    $resume->getFuture()->await();
                    if ($limit === 100) {
                        $query['limit'] = 20;
                    }
                }
                return $api->response($r, $api->history($query));
            };
            $owner = new \Amp\DeferredCancellation();
            $large = async(fn () => $client->getHistoryHtml(['peer' => 'test', 'limit' => $limit], cancellation: $owner->getCancellation()));
            $started->getFuture()->await();
            $small = async(fn () => $client->getHistoryHtml(['peer' => '@TEST', 'limit' => $smaller]));
            delay(0);
            self::assertSame(1, $api->count('getHistoryHtml'));
            $cancel = new \Amp\DeferredCancellation();
            $departing = async(fn () => $client->getHistoryHtml(['peer' => 'test', 'limit' => 5], cancellation: $cancel->getCancellation()));
            delay(0);
            $cancel->cancel();
            self::assertThrows(fn () => $departing->await(), \Amp\CancelledException::class);
            if ($cancelOwner) {
                $owner->cancel();
                self::assertThrows(fn () => $large->await(), \Amp\CancelledException::class);
            }
            $resume->complete();
            if (!$cancelOwner) {
                self::assertCount($limit === 100 ? 20 : 50, $large->await()['messages']);
            }
            self::assertCount($smaller, $small->await()['messages']);
            self::assertSame($limit === 100 ? 2 : 1, $api->count('getHistoryHtml'));
        }
    }

    public function testCleanupIsBatchedWithoutMakingExpiredMessagesReadable(): void
    {
        $clock = new Clock();
        $cache = new TelegramCache(clock: fn () => $clock->time, ttlSeconds: 1, staleTtlSeconds: 2, cleanupIntervalSeconds: 60);
        $api = new ApiFixture();
        $client = new TgClient('fake.test', 9503, cache: $cache, httpClient: $this->createApiClient($api));
        $client->getMessage('test', 7);
        $clock->time += 3;
        $client->getInfo('test');
        self::assertNull($cache->read($cache->peer('test')->messages[7], useStale: true));
        self::assertSame(1, $cache->stats()->messages);
        $clock->time = 1060;
        $client->getInfo('test');
        self::assertSame(0, $cache->stats()->messages);
        self::assertGreaterThan(0, $cache->stats()->payloadBytes);
    }

    public function testGroupsAndPrivateDialogsKeepTheirOwnMessagesInfoAndAccessRules(): void
    {
        [, , $api, $client] = $this->createClient();
        $entities = [
            '-42' => ['_' => 'chat', 'id' => -42, 'title' => 'Group'],
            '123' => ['_' => 'user', 'id' => 123, 'first_name' => 'Person'],
            '-1000000000042' => ['_' => 'channel', 'id' => -1000000000042, 'megagroup' => true, 'title' => 'Supergroup'],
            'me' => ['_' => 'user', 'id' => 456, 'self' => true, 'first_name' => 'Me'],
            'alice' => ['_' => 'user', 'id' => 789, 'username' => 'Alice', 'first_name' => 'Empty dialog'],
        ];
        $api->handlers['getHistoryHtml'] = function ($r, $p) use ($api, $entities) {
            $peer = $p['data']['peer'];
            $entity = $entities[$peer];
            $message = $api->message(7);
            $message['message'] = $peer;
            $message['peer_id'] = $entity['id'];
            return $api->response($r, ['messages' => $peer === 'alice' ? [] : [$message], 'count' => $peer === 'alice' ? 0 : 1,
                'chats' => $entity['_'] === 'user' ? [] : [$entity],
                'users' => $entity['_'] === 'user' ? [$entity] : []]);
        };
        foreach (['-42' => 'chat', '123' => 'user', '-1000000000042' => 'supergroup', 'me' => 'user', 'alice' => 'user'] as $peer => $type) {
            $peer = (string) $peer;
            Config::getInstance()->config['access']['only_public_channels'] = true;
            $client->getHistoryHtml(['peer' => $peer, 'limit' => 1]);
            self::assertSame($type, $client->getInfo($peer)['type']);
            if ($type !== 'supergroup') {
                self::assertSame(403, $this->handle($client, $this->request(channel: $peer))->getStatus());
            }
            Config::getInstance()->config['access']['only_public_channels'] = false;
            self::assertSame(200, $this->handle($client, $this->request(channel: $peer, query: '?limit=1'))->getStatus());
        }
        foreach (array_keys($entities) as $peer) {
            if ($peer === 'alice') {
                continue;
            }
            self::assertSame((string) $peer, $client->getMessage((string) $peer, 7)['message']);
        }
        self::assertSame(5, $api->count('getHistoryHtml'));
        self::assertSame(0, $api->count('getMessages'));
        self::assertSame(0, $api->count('getInfo'));
    }

    public function testStandaloneMessageRefreshDoesNotReplaceHistoryHtmlWithRawText(): void
    {
        [$clock, , $api, $client] = $this->createClient();
        $api->handlers['getHistoryHtml'] = function ($r, $p) use ($api) {
            $history = $api->history($p['data']);
            foreach ($history['messages'] as &$message) {
                $message['message'] = '<b>formatted</b>';
            }
            return $api->response($r, $history);
        };
        $client->getHistoryHtml(['peer' => 'test', 'limit' => 50]);
        $clock->time = 1900;
        $client->getHistoryHtml(['peer' => 'test', 'limit' => 1]);
        $clock->time = 1901;
        $api->handlers['getMessages'] = fn ($r) => $api->response($r, ['messages' => [
            array_replace($api->message(853), ['message' => 'formatted']),
        ]]);
        self::assertSame('formatted', $client->getMessage('test', 853)['message']);
        $page = $client->getHistoryHtml(['peer' => 'test', 'limit' => 1, 'add_offset' => 49]);
        self::assertSame('<b>formatted</b>', $page['messages'][0]['message']);
        self::assertSame(3, $api->count('getHistoryHtml'));
    }

    public function testHeadGrowsAndCompatibleShortRefreshKeepsOrderWithoutRefreshingTheTail(): void
    {
        [$clock, $cache, $api, $client] = $this->createClient();
        $api->handlers['getHistoryHtml'] = function ($r, $p) use ($api) {
            $history = $api->history($p['data']);
            $history['users'] = [['_' => 'user', 'id' => 1, 'first_name' => 'Head']];
            $history['chats'] = [['_' => 'channel', 'id' => 123, 'title' => 'Head']];
            if ($p['data']['limit'] > 10) {
                $history['users'][] = ['_' => 'user', 'id' => 2, 'first_name' => 'Tail'];
                $history['chats'][] = ['_' => 'channel', 'id' => 456, 'title' => 'Tail'];
            }
            return $api->response($r, $history);
        };
        self::assertCount(10, $client->getHistoryHtml(['peer' => 'test'])['messages']);
        $fifty = $client->getHistoryHtml(['peer' => 'test', 'limit' => 50]);
        self::assertCount(50, $fifty['messages']);
        $client->getHistoryHtml(['peer' => '@TEST']);
        self::assertSame(2, $api->count('getHistoryHtml'));
        $clock->time += 60;
        $client->getHistoryHtml(['peer' => 'test', 'limit' => 10]);
        $context = new CacheContext(60);
        $context->readMode = CacheReadMode::Stale;
        $older = $client->getHistoryHtml(['peer' => 'test', 'limit' => 50], $context);
        self::assertSame(array_column($fifty['messages'], 'id'), array_column($older['messages'], 'id'));
        self::assertEquals($fifty['users'], $older['users']);
        self::assertEquals($fifty['chats'], $older['chats']);
        self::assertSame([CacheStatus::STALE, 1000.0], [$context->status, $context->loadedAt]);
        self::assertSame(3, $api->count('getHistoryHtml'));
        $api->handlers['getInfo'] = fn ($r) => $api->response($r, [
            'User' => ['_' => 'user', 'id' => 2, 'first_name' => 'Changed'], 'type' => 'user', 'bot_api_id' => 2,
        ]);
        $client->getInfo('2', 'test');
        $updated = $client->getHistoryHtml(['peer' => 'test', 'limit' => 50], $context);
        self::assertSame('Changed', array_column($updated['users'], 'first_name', 'id')[2]);
        $client->getHistoryHtml(['peer' => 'test', 'limit' => 50]);
        self::assertSame(4, $api->count('getHistoryHtml'));
        foreach ($fifty['messages'] as $message) {
            self::assertSame($message, $client->getMessage('test', $message['id']));
        }
        self::assertSame(0, $api->count('getMessages'));
        self::assertSame(1, $cache->stats()->channels);
    }

    public function testConflictingShortHeadDropsTheOldOrderButKeepsMessagesForMedia(): void
    {
        [$clock, , $api, $client] = $this->createClient();
        $api->handlers['getHistoryHtml'] = fn ($r, $p) => $api->response($r, $api->history($p['data']));
        $client->getHistoryHtml(['peer' => 'test', 'limit' => 50]);
        $clock->time += 60;
        $api->handlers['getHistoryHtml'] = function ($r, $p) use ($api) {
            $history = $api->history($p['data']);
            $history['messages'][0] = $api->message(2000);
            return $api->response($r, $history);
        };
        self::assertSame(2000, $client->getHistoryHtml(['peer' => 'test'])['messages'][0]['id']);
        self::assertSame(970, $client->getMessage('test', 970)['id']);
        $context = new CacheContext(60);
        $context->readMode = CacheReadMode::Stale;
        self::assertCount(50, $client->getHistoryHtml(['peer' => 'test', 'limit' => 50], $context)['messages']);
        self::assertSame(3, $api->count('getHistoryHtml'));
        self::assertSame(0, $api->count('getMessages'));
    }

    public function testHistorySeedsInfoAndResponsesAreRenderedFromSharedEntities(): void
    {
        [, , $api, $client] = $this->createClient();
        $api->handlers['getHistoryHtml'] = function ($r) use ($api) {
            $message = $api->message(7);
            $message['peer_id'] = -1000000000123;
            return $api->response($r, [
                'messages' => [$message], 'count' => 1,
                'chats' => [
                    ['_' => 'channel', 'id' => 999, 'username' => 'other', 'title' => 'Unrelated chat'],
                    ['_' => 'channel', 'id' => -1000000000123, 'username' => 'TeSt', 'title' => 'From history'],
                ],
            ]);
        };
        $client->getHistoryHtml(['peer' => '@TEST']);
        self::assertSame('From history', $client->getInfo('test')['Chat']['title']);
        self::assertSame(0, $api->count('getInfo'));
        $first = $this->handle($client, $this->request('rss'), 'rss');
        $body = buffer($first->getBody());
        $count = count($api->calls);
        Config::getInstance()->config['url'] = 'https://changed.example';
        $next = buffer($this->handle($client, $this->request('rss'), 'rss')->getBody());
        self::assertNotSame($body, $next);
        self::assertStringContainsString('https://changed.example', $next);
        self::assertSame($count, count($api->calls));
        self::assertSame(1, $api->count('getFullInfo'));
    }

    public function testFeedsShareCacheAcrossFormatsIPsAndChannelAliasesUntilExpiry(): void
    {
        [$clock, , $api, $client] = $this->createClient();
        $json = $this->handle($client, $this->request(channel: '@TEST'));
        $body = buffer($json->getBody());
        $clock->time += 59;
        $count = count($api->calls);
        $again = $this->handle($client, $this->request(channel: 'test', query: '?_cb=42&limit=10', ip: '192.0.2.2'));
        self::assertSame($body, buffer($again->getBody()));
        self::assertSame('TelegramRSS; fwd=miss', $json->getHeader('Cache-Status'));
        self::assertSame('TelegramRSS; hit; ttl=1', $again->getHeader('Cache-Status'));
        self::assertSame(array_diff_key($json->getHeaders(), ['cache-status' => true]),
            array_diff_key($again->getHeaders(), ['cache-status' => true]));
        self::assertSame($count, count($api->calls));
        $rss = $this->handle($client, $this->request('rss'), 'rss');
        self::assertStringContainsString('Version 1', buffer($rss->getBody()));
        self::assertNotNull($rss->getHeader('ETag'));
        self::assertNotNull($rss->getHeader('Last-Modified'));
        self::assertSame(1, $api->count('getHistoryHtml'));
        $clock->time += 1;
        $api->version = 2;
        self::assertStringContainsString('Version 2', buffer($this->handle($client, $this->request())->getBody()));
        self::assertSame(2, $api->count('getHistoryHtml'));
    }

    public function testMediaReusesHistoryButDownloadsSeparateStreamsForOriginalPreviewAndRanges(): void
    {
        [$clock, , $api, $client] = $this->createClient();
        $this->handle($client, $this->request());
        $original = $this->handle($client, $this->request('media', 'TEST', extra: ['range' => 'bytes=0-2']), 'media');
        $preview = $this->request('media', extra: ['range' => 'bytes=3-5']);
        $preview->setAttribute(Router::class, ['channel' => 'test', 'message_id' => 7, 'preview' => 'preview']);
        $response = $this->handle($client, $preview, 'media');
        self::assertSame([206, 'bytes 0-2/10', 'abc'], [$original->getStatus(), $original->getHeader('content-range'), buffer($original->getBody())]);
        self::assertSame('xyz', buffer($response->getBody()));
        self::assertSame('TelegramRSS; fwd=bypass; detail=media-stream', $response->getHeader('Cache-Status'));
        self::assertSame(0, $api->count('getMessages'));
        $calls = array_values(array_filter($api->calls, fn ($c) => str_starts_with($c['method'], 'getMedia')));
        self::assertSame([['bytes=0-2'], ['bytes=3-5']], array_column(array_column($calls, 'headers'), 'range'));
        self::assertSame($api->message(7), $calls[0]['params']['data']['message']);
        $repeat = $this->handle($client, $this->request('media', extra: ['range' => 'bytes=0-2']), 'media');
        self::assertSame('abc', buffer($repeat->getBody()));
        self::assertSame(2, $api->count('getMedia'));
        $clock->time += 901;
        $api->version = 2;
        self::assertSame($api->message(7), $client->getMessage('test', 7));
        self::assertSame(1, $api->count('getMessages'));
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
        $clock->time += 901;
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

    public function testFLOODWAITPeerCooldownStaleAgeRetryAfterAndRecovery(): void
    {
        [$clock, $cache, $api, $client] = $this->createClient();
        $body = buffer($this->handle($client, $this->request())->getBody());
        $clock->time += 61;
        $api->handlers['getHistoryHtml'] = fn ($r) => $api->error($r, 'FLOOD_WAIT_30');
        $r = $this->request();
        self::assertSame($body, buffer($this->handle($client, $r)->getBody()));
        self::assertSame(2, $api->count('getHistoryHtml'));
        unset($api->handlers['getHistoryHtml']);
        self::assertSame(200, $this->handle($client, $this->request('json', 'other'))->getStatus());
        self::assertSame(3, $api->count('getHistoryHtml'));
        $count = count($api->calls);
        $response = $this->handle($client, $this->request('json', '@TEST', '?page=11'));
        self::assertSame(429, $response->getStatus());
        self::assertSame('30', $response->getHeader('retry-after'));
        self::assertSame($count, count($api->calls));
        $clock->time += 5;
        $response = $this->handle($client, $this->request('json', 'TEST', '?page=11'));
        self::assertSame('25', $response->getHeader('retry-after'));
        self::assertSame(3, $api->count('getHistoryHtml'));
        $clock->time += 25;
        unset($api->handlers['getHistoryHtml']);
        $api->version = 2;
        self::assertStringContainsString('Version 2', buffer($this->handle($client, $this->request())->getBody()));
        self::assertSame(4, $api->count('getHistoryHtml'));
        $clock->time += 901;
        $api->handlers['getHistoryHtml'] = fn ($r) => $api->error($r, 'FLOOD_WAIT_20');
        self::assertSame(429, $this->handle($client, $this->request())->getStatus());
    }

    public function testWarmFeedFallbackPreservesBodyHeadersAndOriginalFifteenMinuteDeadline(): void
    {
        foreach (['json', 'rss'] as $format) {
            [$clock, , $api, $client] = $this->createClient();
            $first = $this->handle($client, $this->request($format), $format);
            $body = buffer($first->getBody());
            $count = null;
            $api->handlers['getHistoryHtml'] = fn ($r) => $api->error($r, 'FLOOD_WAIT_1000');
            foreach ([1061, 1500, 1900] as $time) {
                $clock->time = $time;
                $response = $this->handle($client, $this->request($format), $format);
                self::assertSame($body, buffer($response->getBody()));
                self::assertSame('TelegramRSS; hit; ttl=' . (1060 - $time), $response->getHeader('Cache-Status'));
                self::assertSame(array_diff_key($first->getHeaders(), ['cache-status' => true]),
                    array_diff_key($response->getHeaders(), ['cache-status' => true]));
                $count ??= count($api->calls);
                self::assertSame($count, count($api->calls));
            }
            self::assertSame(2, $api->count('getHistoryHtml'));
            $clock->time = 1901;
            $response = $this->handle($client, $this->request($format), $format);
            self::assertSame([429, '160'], [$response->getStatus(), $response->getHeader('Retry-After')]);
            self::assertSame($count, count($api->calls));
        }
    }

    public function testAccessRulesApplyToCacheHitsAndPausedPeers(): void
    {
        [$clock, $cache, $api, $client] = $this->createClient();
        $this->handle($client, $this->request());
        $count = count($api->calls);
        foreach ([false, true] as $paused) {
            if ($paused) {
                $cache->floodWait('test', 30);
                $clock->time += 60;
            }
            foreach (['json', 'media'] as $format) {
                $request = $this->request($format);
                $request->setAttribute('user', new User(0));
                $response = $this->handle($client, $request, $format);
                self::assertSame([429, null], [$response->getStatus(), $response->getHeader('Retry-After')]);
                self::assertSame('TelegramRSS; fwd=bypass; detail=ban', $response->getHeader('Cache-Status'));
                Config::getInstance()->config['access']['forbidden_referer_regex'] = 'evil';
                self::assertSame(403, $this->handle($client, $this->request($format, extra: ['referer' => 'https://evil.test']), $format)->getStatus());
                Config::getInstance()->config['access']['forbidden_referer_regex'] = '';
            }
            $auth = new AuthorizationMiddleware();
            (new ReflectionProperty($auth, 'ipBlacklist'))->setValue($auth, ['192.0.2.1' => null]);
            self::assertSame(403, $this->handle($client, $this->request(), auth: $auth)->getStatus());
            Config::getInstance()->config['access']['forbidden_peer_regex'] = '^test$';
            (new ReflectionProperty(ForbiddenPeers::class, 'regex'))->setValue(null, null);
            self::assertSame(403, $this->handle($client, $this->request(channel: '@TEST'))->getStatus());
            Config::getInstance()->config['access']['forbidden_peer_regex'] = '';
            (new ReflectionProperty(ForbiddenPeers::class, 'regex'))->setValue(null, null);
        }
        self::assertSame(404, $this->handle($client, $this->request(channel: 'bad!name'))->getStatus());
        self::assertSame($count, count($api->calls));
    }

    public function testTemporaryBanReportsRemainingSecondsAndExpiresWithoutApiCallsWhileBanned(): void
    {
        ClockMock::withClockMock(1000);
        [, , $api, $client] = $this->createClient();
        $user = new User();
        $user->addBan();
        $request = function () use ($client, $user): Response {
            $request = $this->request('media');
            $request->setAttribute('user', $user);
            return $this->handle($client, $request, 'media');
        };
        foreach ([60, 43] as $remaining) {
            $response = $request();
            self::assertSame([429, (string) $remaining], [$response->getStatus(), $response->getHeader('Retry-After')]);
            self::assertSame('TelegramRSS; fwd=bypass; detail=ban', $response->getHeader('Cache-Status'));
            ClockMock::sleep(17);
        }
        self::assertSame([], $api->calls);
        ClockMock::sleep(26);
        $response = $request();
        self::assertSame([206, null], [$response->getStatus(), $response->getHeader('Retry-After')]);
    }

    public function testMissingMessagesAreRetriedAndDisablingCacheAlwaysLoadsFreshData(): void
    {
        [$clock, , $api, $client] = $this->createClient();
        $client->getHistoryHtml(['peer' => 'test']);
        $clock->time += 901;
        $api->handlers['getMessages'] = fn ($r) => $api->response($r, ['messages' => [['_' => 'messageEmpty', 'id' => 7]]]);
        self::assertThrows(fn () => $client->getMessage('test', 7), UnexpectedValueException::class);
        self::assertThrows(fn () => $client->getMessage('test', 7), UnexpectedValueException::class);
        unset($api->handlers['getMessages']);
        self::assertSame(7, $client->getMessage('test', 7)['id']);
        self::assertSame(3, $api->count('getMessages'));
        [, , $api, $client] = $this->createClient(false);
        self::assertSame('TelegramRSS; fwd=bypass', $this->handle($client, $this->request())->getHeader('Cache-Status'));
        $api->version = 2;
        self::assertStringContainsString('Version 2', buffer($this->handle($client, $this->request())->getBody()));
        self::assertSame(2, $api->count('getHistoryHtml'));
        $client->getMessage('test', 7);
        $client->getMessage('test', 7);
        self::assertSame(2, $api->count('getMessages'));
    }

    public function testLeastRecentlyUsedChannelIsEvictedTogetherAtTheConfiguredLimit(): void
    {
        [, $cache, $api, $client] = $this->createClient(maxChannels: 2);
        $client->getHistoryHtml(['peer' => 'a']);
        $client->getMessage('b', 7);
        $client->getMessage('a', 7);
        $client->getMessage('c', 7);
        self::assertSame(7, $client->getMessage('a', 7)['id']);
        self::assertSame(2, $api->count('getMessages'));
        $client->getMessage('b', 7);
        self::assertSame(3, $api->count('getMessages'));
        self::assertNull($cache->peer('c')->messages[7] ?? null);
        self::assertNotNull($cache->peer('a')->history);
    }

    public function testDefaultsKeepAtMostFiveHundredChannels(): void
    {
        putenv('CACHE_MAX_CHANNELS');
        $config = (include ROOT_DIR . '/config.php')['cache'];
        self::assertSame(500, $config['maxChannels']);
        $cache = new TelegramCache();
        foreach (range(1, 501) as $channel) {
            $peer = $cache->peer('channel' . $channel);
            $peer->messages[7] = CacheEntry::pack(['id' => 7], $cache->now(), 'getMessages');
            $cache->commit('channel' . $channel, $peer);
        }
        self::assertSame(500, $cache->stats()->channels);
        self::assertNull($cache->read($cache->peer('channel1')->messages[7] ?? null));
        self::assertNotNull($cache->read($cache->peer('channel501')->messages[7] ?? null));
    }

    public function testQuotaIncludesCacheHitsAndRejectedRequestsDoNotExtendIt(): void
    {
        ClockMock::withClockMock(1000);
        [$clock, , $api, $client] = $this->createClient();
        $user = new User(2);
        $request = function () use ($client, $user): Response {
            $request = $this->request();
            $request->setAttribute('user', $user);
            return $this->handle($client, $request);
        };
        self::assertSame([200, 200, 429, 429], array_map(fn () => $request()->getStatus(), range(1, 4)));
        self::assertGreaterThan(0, (int) $request()->getHeader('Retry-After'));
        self::assertSame('TelegramRSS; fwd=bypass; detail=rate-limit', $request()->getHeader('Cache-Status'));
        self::assertSame(1, $api->count('getHistoryHtml'));
        self::assertSame([], $user->errors);
        ClockMock::sleep(121);
        $clock->time += 121;
        self::assertSame(200, $request()->getStatus());
        self::assertSame(2, $api->count('getHistoryHtml'));
    }

    public function testAuthenticatedQuotaIsSharedAcrossIPsAndLogsAcceptedUsage(): void
    {
        ClockMock::withClockMock(1000);
        [, , $api, $client] = $this->createClient();
        $access = $this->accessControlForTest(['reader' => ['password' => 'secret', 'rpm' => 2]]);
        $records = [];
        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('log')->andReturnUsing(function ($level, $message, $context) use (&$records): void {
            $records[] = $context['request'];
        });
        $handler = Mockery::mock(RequestHandler::class);
        $handler->shouldReceive('handleRequest')->andReturnUsing(fn ($r) => $this->handle($client, $r));
        $middleware = new AccessLoggerMiddleware($logger, $access);
        foreach (['192.0.2.1' => 200, '192.0.2.2' => 200, '192.0.2.3' => 429] as $ip => $status) {
            $request = $this->request(ip: $ip, extra: ['authorization' => 'Basic ' . base64_encode('reader:secret')]);
            self::assertSame($status, $middleware->handleRequest($request, $handler)->getStatus());
        }
        self::assertSame([1, 2, 2], array_column($records, 'rate_limit_used'));
        self::assertSame(['reader', 0, 2], [$records[2]['username'], $records[2]['rate_limit_remaining'], $records[2]['rpm_limit']]);
        self::assertSame(200, $middleware->handleRequest($this->request(ip: '192.0.2.3'), $handler)->getStatus());
        self::assertSame(1, $api->count('getHistoryHtml'));
    }

    public function testRefreshUpdatesEntireMessagesAndFeedValidatorsWithoutExpiringOtherChannels(): void
    {
        [$clock, , $api, $client] = $this->createClient();
        $old = $this->handle($client, $this->request('rss'), 'rss');
        $clock->time += 30;
        $this->handle($client, $this->request(channel: 'other'));
        $clock->time += 30;
        $api->version = 2;
        $edited = $api->message(7) + ['edit_date' => 1700000010, 'views' => 123, 'entities' => []];
        $edited['media']['document']['file_reference']['bytes'] = 'AAED';
        $api->handlers['getHistoryHtml'] = fn ($r) => $api->response($r, ['messages' => [$edited], 'count' => 1]);
        $fresh = $this->handle($client, $this->request('rss'), 'rss');
        self::assertStringContainsString('Version 2', buffer($fresh->getBody()));
        self::assertNotSame($old->getHeader('ETag'), $fresh->getHeader('ETag'));
        self::assertNotSame($old->getHeader('Last-Modified'), $fresh->getHeader('Last-Modified'));
        self::assertSame($edited, $client->getMessage('test', 7));
        self::assertSame(0, $api->count('getMessages'));
        $count = count($api->calls);
        self::assertStringContainsString('Version 1', buffer($this->handle($client, $this->request(channel: 'other'))->getBody()));
        self::assertSame($count, count($api->calls));
    }

    public function testSlowFeedRenderingDoesNotExtendTheHistoryTTL(): void
    {
        [$clock, , $api, $client] = $this->createClient();
        $api->handlers['getFullInfo'] = function ($r) use ($api, $clock) {
            $clock->time += 60;
            return $api->response($r, ['Chat' => ['title' => 'Test'], 'full' => ['about' => 'Test']]);
        };
        self::assertStringContainsString('Version 1', buffer($this->handle($client, $this->request('rss'), 'rss')->getBody()));
        unset($api->handlers['getFullInfo']);
        $api->version = 2;
        self::assertStringContainsString('Version 2', buffer($this->handle($client, $this->request())->getBody()));
        self::assertSame(2, $api->count('getHistoryHtml'));
    }

    public function testSharedHeadWindowSlicesLimitsPagesPreservesMetadataAndSeedsAllMediaMessages(): void
    {
        [$clock, $cache, $api, $client] = $this->createClient();
        $api->handlers['getHistoryHtml'] = fn ($r, $p) => $api->response($r, $api->history($p['data']));
        $client->getHistoryHtml(['peer' => 'test', 'limit' => 100]);
        foreach ([['', 0, 10], ['?limit=20', 0, 20], ['?page=10', 90, 10], ['?limit=30&page=3', 60, 30]] as [$query, $offset, $limit]) {
            $response = $this->handle($client, $this->request('json', '@TEST', $query));
            self::assertSame($api->history(['limit' => $limit, 'add_offset' => $offset, 'offset_id' => 0]) + ['sponsored_messages' => []], json_decode(buffer($response->getBody()), true));
            self::assertSame(1, $api->count('getHistoryHtml'));
        }
        $call = array_values(array_filter($api->calls, fn ($call) => $call['method'] === 'getHistoryHtml'))[0];
        self::assertSame(100, $call['params']['data']['limit']);
        self::assertSame(0, $call['params']['data']['add_offset']);
        self::assertSame('test', $call['params']['data']['peer']);
        $rss = $this->handle($client, $this->request('rss', query: '?limit=3'), 'rss');
        self::assertSame(1, $api->count('getHistoryHtml'));
        $count = count($api->calls);
        $rssAgain = $this->handle($client, $this->request('rss', 'TEST', '?limit=3&_cb=42'), 'rss');
        self::assertSame('TelegramRSS; hit; ttl=60', $rssAgain->getHeader('Cache-Status'));
        self::assertSame(array_diff_key($rss->getHeaders(), ['cache-status' => true]),
            array_diff_key($rssAgain->getHeaders(), ['cache-status' => true]));
        self::assertSame(buffer($rss->getBody()), buffer($rssAgain->getBody()));
        self::assertSame($count, count($api->calls));
        self::assertSame($api->message(703), $client->getMessage('test', 703)); // Position 100, never returned in the small feeds above.
        self::assertSame(0, $api->count('getMessages'));
        $clock->time += 60;
        $api->version = 2;
        $response = $this->handle($client, $this->request(query: '?page=2&limit=5'));
        self::assertSame('Version 2', json_decode(buffer($response->getBody()), true)['messages'][0]['message']);
        self::assertSame(2, $api->count('getHistoryHtml'));
    }

    public function testOldPagesAndAnchorsAreNeverCachedOrServedAsFloodWaitFallback(): void
    {
        [, , $api, $client] = $this->createClient();
        $api->handlers['getHistoryHtml'] = fn ($r, $p) => $api->response($r, $api->history($p['data']));
        foreach (['?page=11' => range(700, 673, -3), '?limit=30&page=4' => range(730, 643, -3),
            '?id=700' => range(700, 673, -3), '?id=-1' => range(1000, 973, -3)] as $query => $ids) {
            $before = $api->count('getHistoryHtml');
            $first = $this->handle($client, $this->request(query: $query));
            $second = $this->handle($client, $this->request(query: $query));
            $body = buffer($first->getBody());
            self::assertSame($ids, array_column(json_decode($body, true)['messages'], 'id'));
            self::assertSame($body, buffer($second->getBody()));
            self::assertSame('TelegramRSS; fwd=bypass', $second->getHeader('Cache-Status'));
            self::assertSame($before + 2, $api->count('getHistoryHtml'));
        }
        $this->handle($client, $this->request());
        $api->handlers['getHistoryHtml'] = fn ($r) => $api->error($r, 'FLOOD_WAIT_30');
        $response = $this->handle($client, $this->request(query: '?page=11'));
        self::assertSame([429, '30'], [$response->getStatus(), $response->getHeader('Retry-After')]);
        self::assertSame(429, $this->handle($client, $this->request('rss', query: '?id=700'), 'rss')->getStatus());
    }

    public function testIdenticalHeadQueriesCoalesceAcrossFormatsAndLargerQueriesLoadTheirOwnRange(): void
    {
        [, , $api, $client] = $this->createClient();
        $api->handlers['getHistoryHtml'] = function ($r, $p) use ($api) {
            delay(0.01);
            return $api->response($r, $api->history($p['data']));
        };
        $responses = await([
            async(fn () => $this->handle($client, $this->request(query: '?limit=10'))),
            async(fn () => $this->handle($client, $this->request('rss', '@TEST', '?limit=10'), 'rss')),
            async(fn () => $this->handle($client, $this->request(query: '?limit=50'))),
        ]);
        self::assertSame([200, 200, 200], array_map(fn ($response) => $response->getStatus(), $responses));
        self::assertCount(50, json_decode(buffer($responses[2]->getBody()), true)['messages']);
        self::assertSame(2, $api->count('getHistoryHtml'));
        $limits = array_map(fn ($call) => $call['params']['data']['limit'], array_filter($api->calls, fn ($call) => $call['method'] === 'getHistoryHtml'));
        sort($limits);
        self::assertSame([10, 50], $limits);
    }

    public function testConcurrentOldPagesShareOnlyTheInFlightLoadAndRecoverAfterFloodWait(): void
    {
        [$clock, , $api, $client] = $this->createClient();
        $api->handlers['getHistoryHtml'] = function ($r, $p) use ($api) {
            delay(0.01);
            return $api->response($r, $api->history($p['data']));
        };
        $load = fn () => $this->handle($client, $this->request(query: '?page=11'));
        $responses = await([async($load), async($load)]);
        self::assertSame(buffer($responses[0]->getBody()), buffer($responses[1]->getBody()));
        self::assertSame(1, $api->count('getHistoryHtml'));
        $load();
        self::assertSame(2, $api->count('getHistoryHtml'));
        $api->handlers['getHistoryHtml'] = fn ($r) => $api->error($r, 'FLOOD_WAIT_10');
        self::assertSame(429, $load()->getStatus());
        $clock->time += 10;
        unset($api->handlers['getHistoryHtml']);
        self::assertSame(200, $load()->getStatus());
    }

    public function testPartialHistoryUsesWholeQueriesUnlessTheEndIsKnown(): void
    {
        foreach ([true, false] as $hasCount) {
            [, , $api, $client] = $this->createClient();
            $api->handlers['getHistoryHtml'] = function ($r, $p) use ($api, $hasCount) {
                $data = $p['data'];
                if ($data['limit'] === 100) {
                    $data['limit'] = 20;
                }
                $history = $api->history($data);
                if (!$hasCount) {
                    unset($history['count']);
                }
                return $api->response($r, $history);
            };
            foreach (['?limit=30' => 30, '?limit=100' => 20] as $query => $expected) {
                foreach ([1, 2] as $_) {
                    $response = $this->handle($client, $this->request(query: $query));
                    self::assertCount($expected, json_decode(buffer($response->getBody()), true)['messages']);
                }
            }
            self::assertSame(3, $api->count('getHistoryHtml'));
        }
        [, , $api, $client] = $this->createClient();
        $api->handlers['getHistoryHtml'] = fn ($r, $p) => $api->response($r, $api->history($p['data'], 12));
        $client->getHistoryHtml(['peer' => 'test', 'limit' => 100]);
        $page = json_decode(buffer($this->handle($client, $this->request(query: '?page=2'))->getBody()), true);
        self::assertSame([970, 967], array_column($page['messages'], 'id'));
        self::assertSame([], json_decode(buffer($this->handle($client, $this->request(query: '?page=3'))->getBody()), true)['messages']);
        self::assertSame(1, $api->count('getHistoryHtml'));
    }

    public function testPeerPauseCoversAllMethodsAndAliasesButNotOtherChannels(): void
    {
        [$clock, $cache, $api, $client] = $this->createClient();
        $client->getHistoryHtml(['peer' => 'test']);
        $clock->time += 60;
        $api->handlers['getMessages'] = fn ($r) => $api->error($r, 'FLOOD_WAIT_30');
        self::assertSame($api->message(7), $client->getMessage('@TEST', 7));
        self::assertThrows(fn () => $client->getMessage('@TEST', 9), FloodWaitException::class);
        $count = count($api->calls);
        foreach ([fn () => $client->getMessage('TEST', 100), fn () => $client->getInfo('test'),
            fn () => $client->getHistoryHtml(['peer' => '@TEST']), fn () => $client->getMedia(['peer' => 'test', 'id' => [7]], [])] as $call) {
            self::assertSame(30, self::assertThrows($call, FloodWaitException::class)->retryAfter);
        }
        $response = $this->handle($client, $this->request('media'), 'media');
        self::assertSame([429, '30'], [$response->getStatus(), $response->getHeader('Retry-After')]);
        self::assertSame(429, $this->handle($client, $this->request('rss'), 'rss')->getStatus());
        self::assertSame($count, count($api->calls));
        $client->getFullInfo('other');
        self::assertSame($count + 1, count($api->calls));
        $clock->time += 5;
        self::assertSame(25, $cache->floodWait('@TEST', 10)->retryAfter);
        $clock->time += 25;
        unset($api->handlers['getMessages']);
        self::assertSame(99, $client->getMessage('test', 99)['id']);
    }

    public function testFloodWaitHeadersIncludeRemainingCooldownWithoutExtraApiCalls(): void
    {
        [$clock, , $api, $client] = $this->createClient();
        $api->handlers['getHistoryHtml'] = fn ($r) => $api->error($r, 'FLOOD_WAIT_30');
        $response = $this->handle($client, $this->request());
        self::assertSame([429, '30', 'no-store', 'TelegramRSS; fwd=bypass; detail=flood-wait'],
            [$response->getStatus(), $response->getHeader('Retry-After'), $response->getHeader('Cache-Control'), $response->getHeader('Cache-Status')]);
        $count = count($api->calls);
        $clock->time += 5;
        foreach (['json', 'media'] as $format) {
            $response = $this->handle($client, $this->request($format), $format);
            self::assertSame([429, '25', 'no-store', 'TelegramRSS; fwd=bypass; detail=flood-wait'],
                [$response->getStatus(), $response->getHeader('Retry-After'), $response->getHeader('Cache-Control'), $response->getHeader('Cache-Status')]);
        }
        self::assertSame($count, count($api->calls));
    }

    public function testMediaSearchesStaleMessagesBeforeApiWithoutExtendingTheirOriginalAge(): void
    {
        foreach (['history', 'getMessages'] as $source) {
            [$clock, , $api, $client] = $this->createClient();
            if ($source === 'history') {
                $client->getHistoryHtml(['peer' => 'test']);
            } else {
                $client->getMessage('test', 7);
            }
            $count = count($api->calls);
            $api->handlers['getMessages'] = fn ($r) => $api->error($r, 'CHANNEL_PRIVATE');
            foreach ([1060, 1900] as $time) {
                $clock->time = $time;
                $context = new CacheContext(60);
                self::assertSame($api->message(7), $client->getMessage('@TEST', 7, $context));
                self::assertSame([CacheStatus::STALE, $source, 1000.0], [$context->status, $context->messageSource, $context->loadedAt]);
                self::assertSame($count, count($api->calls));
            }
            $clock->time = 1901;
            self::assertThrows(fn () => $client->getMessage('test', 7), UnexpectedValueException::class);
            self::assertSame($count + 1, count($api->calls));
            $api->handlers['getMessages'] = fn ($r) => $api->error($r, 'FLOOD_WAIT_1000');
            self::assertThrows(fn () => $client->getMessage('test', 7), FloodWaitException::class);
        }
    }

    public function testHistoryAndMediaLookupsShareOneCacheEntryPerChannel(): void
    {
        [, $cache, $api, $client] = $this->createClient();
        $api->handlers['getHistoryHtml'] = fn ($r, $p) => $api->response($r, $api->history($p['data']));
        foreach (['first', 'second', 'third'] as $channel) {
            $history = $client->getHistoryHtml(['peer' => $channel, 'limit' => 100]);
            self::assertSame($history['messages'], array_map(
                fn ($message) => $client->getMessage($channel, $message['id']), $history['messages'],
            ));
        }
        self::assertSame(0, $api->count('getMessages'));
        self::assertSame(3, $cache->stats()->channels);
    }

    public function testAsyncMediaMetadataAttributesFLOODWAITToTheFeedPeerAndWarmFallbackKeepsItsAge(): void
    {
        [$clock, $cache, $api, $client] = $this->createClient();
        $api->handlers['getHistoryHtml'] = function ($r, $p) use ($api) {
            $message = $api->message(7);
            $message['media']['document']['id'] = $p['data']['peer'] === 'test' ? 42 : 99;
            return $api->response($r, ['messages' => [$message], 'count' => 1]);
        };
        $r = $this->request('rss');
        $first = $this->handle($client, $r, 'rss');
        $body = buffer($first->getBody());
        $clock->time += 61;
        $api->handlers['getDownloadInfo'] = function ($r, $p) use ($api) {
            delay(0.01);
            return $p['message']['document']['id'] === 42 ? $api->error($r, 'FLOOD_WAIT_1000')
                : $api->response($r, ['size' => 10, 'mime' => 'video/mp4', 'name' => 'video', 'ext' => '.mp4']);
        };
        $second = $this->handle($client, $this->request('rss'), 'rss');
        self::assertSame(200, $second->getStatus());
        self::assertSame($body, buffer($second->getBody()));
        self::assertSame('TelegramRSS; hit; ttl=-1', $second->getHeader('Cache-Status'));
        self::assertSame(array_diff_key($first->getHeaders(), ['cache-status' => true]),
            array_diff_key($second->getHeaders(), ['cache-status' => true]));
        self::assertSame(1000, self::assertThrows(fn () => $cache->checkCooldown('@TEST'), FloodWaitException::class)->retryAfter);
        self::assertSame(200, $this->handle($client, $this->request('rss', 'other'), 'rss')->getStatus());
    }

    public function testPeerPauseAppearingDuringACoalescedMessageLookupBlocksTheSubsequentDownload(): void
    {
        [$clock, $cache, $api, $client] = $this->createClient();
        /** @var DeferredFuture<void> $started */
        $started = new DeferredFuture();
        /** @var DeferredFuture<void> $resume */
        $resume = new DeferredFuture();
        $api->handlers['getMessages'] = function ($r) use ($api, $started, $resume) {
            $started->complete();
            $resume->getFuture()->await();
            return $api->response($r, ['messages' => [$api->message(7)]]);
        };
        $lookup = async(fn () => $client->getMessage('test', 7));
        $started->getFuture()->await();
        $media = async(fn () => $client->getMedia(['peer' => 'test', 'id' => [7]], ['range' => ['bytes=0-2']]));
        delay(0); // Let the media caller join the already-running message Future.
        $cache->floodWait('test', 30);
        $resume->complete();
        $lookup->await();
        self::assertSame(30, self::assertThrows(fn () => $media->await(), FloodWaitException::class)->retryAfter);
        self::assertSame(0, $api->count('getMedia'));
        self::assertSame(1, $api->count('getMessages'));
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

    private function createApiClient(ApiFixture $api): HttpClient
    {
        $delegate = Mockery::mock(DelegateHttpClient::class);
        $delegate->shouldReceive('request')->andReturnUsing($api->request(...));
        return new HttpClient($delegate, []);
    }

    /**
     * @return array{Clock, TelegramCache, ApiFixture, TgClient}
     */
    private function createClient(bool $enabled = true, int $maxChannels = 500): array
    {
        $clock = new Clock();
        $cache = new TelegramCache($enabled, $maxChannels, clock: fn () => $clock->time);
        $api = new ApiFixture();
        $client = new TgClient('fake.test', 9503, cache: $cache, httpClient: $this->createApiClient($api));
        return [$clock, $cache, $api, $client];
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

    /**
     * @param 'json'|'rss'|'media' $format
     */
    private function handle(TgClient $client, Request $request, string $format = 'json', ?AuthorizationMiddleware $auth = null): Response
    {
        $validator = new RequestValidatorMiddleware($client, true);
        $controller = match ($format) {
            'json' => new JsonController($client),
            'rss' => new RSSController($client),
            'media' => new MediaController($client),
        };
        return stackMiddleware($controller, $auth ?? new AuthorizationMiddleware(), $validator,
            new CacheMiddleware($client, $validator, $format))->handleRequest($request);
    }
}
