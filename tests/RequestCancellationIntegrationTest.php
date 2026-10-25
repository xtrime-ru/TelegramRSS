<?php

namespace TelegramRSS\Tests;

use Amp\CancelledException;
use Amp\DeferredCancellation;
use Amp\DeferredFuture;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Connection\ConnectionLimitingPool;
use Amp\Http\Client\Request as ClientRequest;
use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\Driver\DefaultHttpDriverFactory;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler\ClosureRequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\SocketHttpServer;
use Amp\TimeoutCancellation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Psr\Log\LoggerInterface;
use TelegramRSS\Cache\TelegramCache;
use TelegramRSS\Server\ReadAheadHttpDriverFactory;
use TelegramRSS\TgClient;

use function Amp\async;

#[Group('network')]
final class RequestCancellationIntegrationTest extends TestCase
{
    public function testHttp1ReadSideDisconnectIsCancelledWithoutLoggingAServerError(): void
    {
        $started = new DeferredFuture;
        $cancelled = new DeferredFuture;
        $errors = [];
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('error')->willReturnCallback(static function ($message) use (&$errors): void {
            $errors[] = $message;
        });
        $server = $this->server(new ClosureRequestHandler(static function (Request $request) use ($started, $cancelled): Response {
            $started->complete();
            try {
                (new DeferredFuture)->getFuture()->await($request->getCancellation());
            } catch (CancelledException $exception) {
                $cancelled->complete();
                throw $exception;
            }
            return new Response(200);
        }), $logger);
        $socket = \Amp\Socket\connect('tcp://' . $server->getServers()[0]->getAddress());
        try {
            $socket->write("GET /json/test HTTP/1.1\r\nHost: localhost\r\n\r\n");
            $started->getFuture()->await(new TimeoutCancellation(2));
            $socket->end();
            $cancelled->getFuture()->await(new TimeoutCancellation(2));
            self::assertNull($socket->read(new TimeoutCancellation(2)));
            self::assertSame([], $errors);
        } finally {
            $socket->close();
            $server->stop();
        }
    }

    public function testLargeRequestsAndFollowingRequestsSucceed(): void
    {
        $server = $this->server(new ClosureRequestHandler(static fn (Request $request) =>
            new Response(200, [], $request->getBody()->buffer() ?: 'ok')));
        try {
            $client = HttpClientBuilder::buildDefault();
            $url = 'http://' . $server->getServers()[0]->getAddress();
            $body = str_repeat('x', 200000);
            self::assertSame($body, $client->request(new ClientRequest($url, 'POST', $body))->getBody()->buffer());
            self::assertSame('ok', $client->request(new ClientRequest($url))->getBody()->buffer());
        } finally {
            $server->stop();
        }
    }

    #[DataProvider('disconnectPhases')]
    public function testDisconnectClosesUpstreamConnectionAndSharedClientRemainsUsable(bool $streaming): void
    {
        $started = new DeferredFuture;
        $closed = new DeferredFuture;
        $release = new DeferredFuture;
        $calls = 0;
        $tas = $this->server(new ClosureRequestHandler(static function (Request $request) use ($started, $closed, $release, $streaming, &$calls): Response {
            if (++$calls === 1) {
                $request->getClient()->onClose(static function () use ($closed, $release): void {
                    $closed->complete();
                    if (!$release->isComplete()) {
                        $release->complete();
                    }
                });
                $started->complete();
                if ($streaming) {
                    return new Response(200, [], new \Amp\ByteStream\ReadableIterableStream((static function () use ($release): \Generator {
                        yield 'first';
                        $release->getFuture()->await();
                        yield 'last';
                    })()));
                }
                $release->getFuture()->await();
            }
            return new Response(200, ['content-type' => 'application/json'], '{"response":{"title":"ok"}}');
        }));
        $client = new TgClient('127.0.0.1', $tas->getServers()[0]->getAddress()->getPort(), 'test', 'test', new TelegramCache(false));
        $rss = $this->server(new ClosureRequestHandler(static function (Request $request) use ($client, $streaming): Response {
            $cancellation = $request->getCancellation();
            if ($streaming) {
                $result = $client->downloadToResponse([], [], 'test', $cancellation);
                return new Response(200, [], $result->getBody());
            }
            $result = $client->getFullInfo('test', $cancellation);
            return new Response(200, [], json_encode($result));
        }));
        $cancel = new DeferredCancellation;
        try {
            $browser = HttpClientBuilder::buildDefault();
            $pending = async(fn () => $browser->request(new ClientRequest('http://' . $rss->getServers()[0]->getAddress()), $cancel->getCancellation()));
            $started->getFuture()->await(new TimeoutCancellation(2));
            if ($streaming) {
                $body = $pending->await(new TimeoutCancellation(2))->getBody();
                self::assertSame('first', $body->read(new TimeoutCancellation(2)));
                $pending = async(\Amp\ByteStream\buffer(...), $body);
            }
            $cancel->cancel();
            try {
                $pending->await();
                self::fail('Expected cancellation');
            } catch (CancelledException) {
                $closed->getFuture()->await(new TimeoutCancellation(2));
            }
            self::assertSame(['title' => 'ok'], $client->getFullInfo('test'));
            self::assertSame(2, $calls);
        } finally {
            if (!$release->isComplete()) {
                $release->complete();
            }
            $rss->stop();
            $tas->stop();
        }
    }

    public static function disconnectPhases(): iterable
    {
        yield 'waiting for response' => [false];
        yield 'downloading media' => [true];
    }

    #[DataProvider('http2Methods')]
    public function testCancellingHttp2RequestDoesNotInterruptAnotherRequest(string $method): void
    {
        $firstStarted = new DeferredFuture;
        $secondStarted = new DeferredFuture;
        $cancelled = new DeferredFuture;
        $release = new DeferredFuture;
        $server = $this->server(new ClosureRequestHandler(static function (Request $request) use ($firstStarted, $secondStarted, $cancelled, $release): Response {
            $first = $request->getUri()->getPath() === '/cancel';
            ($first ? $firstStarted : $secondStarted)->complete();
            try {
                $release->getFuture()->await($request->getCancellation());
            } catch (CancelledException $e) {
                $cancelled->complete();
                throw $e;
            }
            return new Response(200, [], 'ok');
        }));
        $cancel = new DeferredCancellation;
        $client = (new HttpClientBuilder)->usingPool(ConnectionLimitingPool::byAuthority(1))->retry(0)->build();
        $url = 'http://' . $server->getServers()[0]->getAddress();
        $request = static function (string $path) use ($method, $url): ClientRequest {
            $request = new ClientRequest($url . $path, $method, $method === 'POST' ? 'body' : '');
            $request->setProtocolVersions(['2']);
            return $request;
        };
        try {
            $first = async(fn () => $client->request($request('/cancel'), $cancel->getCancellation()));
            $firstStarted->getFuture()->await(new TimeoutCancellation(2));
            $second = async(fn () => $client->request($request('/keep')));
            $secondStarted->getFuture()->await(new TimeoutCancellation(2));
            $cancel->cancel();
            try {
                $first->await(new TimeoutCancellation(2));
                self::fail('Expected cancellation');
            } catch (CancelledException) {
                $cancelled->getFuture()->await(new TimeoutCancellation(2));
            }
            $release->complete();
            self::assertSame('ok', $second->await(new TimeoutCancellation(2))->getBody()->buffer());
        } finally {
            if (!$release->isComplete()) {
                $release->complete();
            }
            $server->stop();
        }
    }

    public static function http2Methods(): iterable
    {
        yield 'without body' => ['GET'];
        yield 'with body' => ['POST'];
    }

    private function server(ClosureRequestHandler $handler, ?LoggerInterface $logger = null): SocketHttpServer
    {
        $logger ??= new NullLogger;
        $factory = new ReadAheadHttpDriverFactory(new DefaultHttpDriverFactory($logger, bodySizeLimit: 300000, allowHttp2Upgrade: true));
        $server = SocketHttpServer::createForDirectAccess($logger, httpDriverFactory: $factory);
        $server->expose('127.0.0.1:0');
        $server->start($handler, new DefaultErrorHandler);
        return $server;
    }
}
