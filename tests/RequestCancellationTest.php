<?php

namespace TelegramRSS\Tests;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\DeferredCancellation;
use Amp\DeferredFuture;
use Amp\Http\Client\DelegateHttpClient;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\Request as ApiRequest;
use Amp\Http\Client\Response as ApiResponse;
use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler\ClosureRequestHandler;
use Amp\Http\Server\Response;
use Amp\TimeoutCancellation;
use Closure;
use League\Uri\Http;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use TelegramRSS\Cache\TelegramCache;
use TelegramRSS\TgClient;

use function Amp\async;
use function Amp\delay;

final class RequestCancellationTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function testCancelledTransportIsNotRetried(): void
    {
        $calls = 0;
        $client = $this->client(static function () use (&$calls): never {
            ++$calls;
            throw new CancelledException;
        });
        try {
            $client->getFullInfo('test');
            self::fail('Expected cancellation');
        } catch (CancelledException) {
            self::assertSame(1, $calls);
        }
    }

    public function testCancellingOneRequestDoesNotCancelAnother(): void
    {
        $started = new DeferredFuture;
        $cancel = new DeferredCancellation;
        $calls = 0;
        $client = $this->client(function (ApiRequest $request, Cancellation $cancellation) use ($started, &$calls): ApiResponse {
            if (++$calls === 1) {
                $started->complete();
                delay(60, cancellation: $cancellation);
            }
            return $this->response($request, ['title' => 'ok']);
        });
        $first = async(fn () => $client->getFullInfo('first', $cancel->getCancellation()));
        $started->getFuture()->await(new TimeoutCancellation(1));
        $cancel->cancel();
        try {
            $first->await(new TimeoutCancellation(1));
            self::fail('Expected cancellation');
        } catch (CancelledException) {
            self::assertSame(['title' => 'ok'], $client->getFullInfo('second'));
            self::assertSame(2, $calls);
        }
    }

    public function testCancellationWhileReadingResponseBodyIsPropagated(): void
    {
        $started = new DeferredFuture;
        $cancel = new DeferredCancellation;
        $chunks = new \Amp\Pipeline\Queue;
        $calls = 0;
        $client = $this->client(static function (ApiRequest $request) use ($chunks, $started, &$calls): ApiResponse {
            ++$calls;
            $started->complete();
            return new ApiResponse('1.1', 200, null, ['content-type' => 'application/json'],
                new \Amp\ByteStream\ReadableIterableStream($chunks->iterate()), $request);
        });
        $response = async(fn () => $client->getFullInfo('test', $cancel->getCancellation()));
        $started->getFuture()->await(new TimeoutCancellation(1));
        $cancel->cancel();
        $this->assertCancelled($response);
        self::assertSame(1, $calls);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    #[DataProvider('feedReaders')]
    public function testCancelledFeedReaderDoesNotAffectOtherReaders(bool $cancelLastReader): void
    {
        define('ROOT_DIR', dirname(__DIR__));
        $GLOBALS['options'] = ['docker' => false];
        [$firstRequest, $disconnectFirst] = $this->request();
        [$secondRequest, $disconnectSecond] = $this->request();
        [$nextRequest] = $this->request();
        foreach ([$firstRequest, $secondRequest, $nextRequest] as $request) {
            $request->setAttribute('channel', 'test');
            $request->setAttribute(\Amp\Http\Server\Router::class, ['channel' => 'test']);
        }
        $started = new DeferredFuture;
        $finished = new DeferredFuture;
        $release = new DeferredFuture;
        $calls = 0;
        $client = $this->client(function (ApiRequest $request, Cancellation $cancellation) use ($started, $finished, $release, &$calls): ApiResponse {
            $method = basename($request->getUri()->getPath());
            if ($method === 'getInfo') {
                return $this->response($request, ['type' => 'channel']);
            }
            if ($method === 'getSelf') {
                return $this->response($request, ['premium' => true]);
            }
            if (++$calls === 1) {
                $started->complete();
                try {
                    $release->getFuture()->await($cancellation);
                } finally {
                    $finished->complete();
                }
            }
            return $this->response($request, ['messages' => [['_' => 'message', 'id' => 7]], 'count' => 1]);
        }, new TelegramCache(true));
        $validator = new \TelegramRSS\Controller\RequestValidatorMiddleware($client, true);
        $handler = \Amp\Http\Server\Middleware\stackMiddleware(
            new \TelegramRSS\Controller\JsonController($client),
            new \TelegramRSS\Server\CacheMiddleware($client, $validator, 'json'),
        );
        $first = async(fn () => $handler->handleRequest($firstRequest));
        $started->getFuture()->await(new TimeoutCancellation(1));
        $second = async(fn () => $handler->handleRequest($secondRequest));
        delay(0.001);
        $disconnectFirst();
        $this->assertCancelled($first);
        self::assertFalse($finished->isComplete());
        if ($cancelLastReader) {
            $disconnectSecond();
            $this->assertCancelled($second);
            $finished->getFuture()->await(new TimeoutCancellation(1));
            $response = $handler->handleRequest($nextRequest);
            self::assertSame(2, $calls);
        } else {
            $release->complete();
            $response = $second->await(new TimeoutCancellation(1));
            self::assertSame(1, $calls);
        }
        self::assertSame(7, json_decode(\Amp\ByteStream\buffer($response->getBody()), true)['messages'][0]['id']);
    }

    public static function feedReaders(): iterable
    {
        yield 'one reader remains' => [false];
        yield 'all readers disconnect' => [true];
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCancellationIsLoggedAs499AndDoesNotCountAsUserError(): void
    {
        define('ROOT_DIR', dirname(__DIR__));
        $GLOBALS['options'] = ['docker' => false];
        [$request] = $this->request();
        $user = new \TelegramRSS\AccessControl\User(1000, 2);
        $access = Mockery::mock(\TelegramRSS\AccessControl\AccessControl::class);
        $access->shouldReceive('checkAuth')->andReturnNull();
        $access->shouldReceive('getOrCreateUser')->andReturn($user);
        $logger = new class extends \Psr\Log\AbstractLogger {
            public array $entries = [];
            public function log($level, $message, array $context = []): void
            {
                $this->entries[] = $context;
            }
        };
        $handler = \Amp\Http\Server\Middleware\stackMiddleware(
            new ClosureRequestHandler(static fn () => throw new CancelledException),
            new \TelegramRSS\Server\AccessLoggerMiddleware($logger, $access),
            new \TelegramRSS\Server\AuthorizationMiddleware,
        );
        try {
            $handler->handleRequest($request);
            self::fail('Expected cancellation');
        } catch (CancelledException) {
            self::assertSame([], $user->errors);
            self::assertFalse($user->isBanned());
            self::assertSame(499, $logger->entries[0]['response']['status']);
        }
    }

    private function client(Closure $handler, ?TelegramCache $cache = null): TgClient
    {
        $delegate = Mockery::mock(DelegateHttpClient::class);
        $delegate->shouldReceive('request')->andReturnUsing($handler);
        return new TgClient('fake.test', 9503, 'test', 'test', $cache ?? new TelegramCache(false), new HttpClient($delegate, []));
    }

    private function assertCancelled(\Amp\Future $future): void
    {
        try {
            $future->await(new TimeoutCancellation(1));
            self::fail('Expected cancellation');
        } catch (CancelledException) {
        }
    }

    private function response(ApiRequest $request, array $data): ApiResponse
    {
        return new ApiResponse('1.1', 200, null, ['content-type' => 'application/json'], json_encode(['response' => $data]), $request);
    }

    /** @return array{Request, Closure(): void} */
    private function request(): array
    {
        $disconnect = new DeferredCancellation;
        $client = Mockery::mock(Client::class);
        $client->shouldReceive('getRemoteAddress')->andReturn(new \Amp\Socket\InternetAddress('127.0.0.1', 1000));
        $request = new Request($client, 'GET', Http::new('http://localhost/media/test/7'));
        $request->setAttribute(DeferredCancellation::class, $disconnect);
        $request = $request->withCancellation($disconnect->getCancellation());
        return [$request, static fn () => $disconnect->cancel()];
    }
}
