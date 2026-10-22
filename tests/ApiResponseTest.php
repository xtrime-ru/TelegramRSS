<?php

namespace TelegramRSS\Tests;

use Amp\Http\Client\DelegateHttpClient;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TelegramRSS\Cache\TelegramCache;
use TelegramRSS\TgClient;
use UnexpectedValueException;

final class ApiResponseTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function testExplicitNullAvatarReturns404WithoutDownload(): void
    {
        $client = $this->client('{"success":true,"response":null,"errors":[]}');
        self::assertNull($client->getPropicInfo('lht_hn'));

        $client = Mockery::mock(TgClient::class);
        $client->shouldReceive('getPropicInfo')->once()->with('lht_hn')->andReturnNull();
        $client->shouldNotReceive('downloadToResponse');
        $request = new \Amp\Http\Server\Request(
            Mockery::mock(\Amp\Http\Server\Driver\Client::class),
            'GET',
            \League\Uri\Http::new('http://localhost/icon/lht_hn/icon.jpg'),
        );
        $request->setAttribute('channel', 'lht_hn');
        $response = (new \TelegramRSS\Controller\IconController($client))->handleRequest($request);
        self::assertSame(404, $response->getStatus());
        self::assertNull($response->getHeader('location'));
    }

    public function testAvatarAndOtherArrayResultsArePreserved(): void
    {
        self::assertSame(['botApiFileId' => 'avatar'], $this->client('{"response":{"botApiFileId":"avatar"}}')->getPropicInfo('test'));
        self::assertSame([], $this->client('{"response":[]}')->getPropicInfo('test'));
        self::assertSame([], $this->client('{"response":[]}')->getFullInfo('test'));
    }

    public function testIntegerAndStringPeerIdsAreSupported(): void
    {
        self::assertSame('-100123', $this->client('{"response":-100123}')->getId('test', 'test'));
        self::assertSame('-100123', $this->client('{"response":"-100123"}')->getId('test', 'test'));
    }

    #[DataProvider('invalidResponses')]
    public function testInvalidResponsesThrow(string $method, string $body, string $contentType, string $exception): void
    {
        $this->expectException($exception);
        $this->client($body, $contentType)->$method(...($method === 'getId' ? ['test', 'test'] : ['test']));
    }

    public static function invalidResponses(): iterable
    {
        foreach (['getPropicInfo', 'getFullInfo', 'getId'] as $method) {
            yield "$method missing response" => [$method, '{"success":true}', 'application/json', UnexpectedValueException::class];
            yield "$method HTML" => [$method, '<html>Error</html>', 'text/html', UnexpectedValueException::class];
            yield "$method invalid JSON" => [$method, '', 'application/json', \JsonException::class];
            yield "$method scalar JSON" => [$method, 'null', 'application/json', UnexpectedValueException::class];
            yield "$method wrong result type" => [$method, '{"response":false}', 'application/json', UnexpectedValueException::class];
            yield "$method unsuccessful response" => [$method, '{"success":false,"response":null}', 'application/json', UnexpectedValueException::class];
            yield "$method errors" => [$method, '{"response":[],"errors":[{"message":"Failed"}]}', 'application/json', UnexpectedValueException::class];
        }
        yield 'null for non-avatar method' => ['getFullInfo', '{"response":null}', 'application/json', UnexpectedValueException::class];
        yield 'null peer ID' => ['getId', '{"response":null}', 'application/json', UnexpectedValueException::class];
        yield 'array peer ID' => ['getId', '{"response":[]}', 'application/json', UnexpectedValueException::class];
    }

    private function client(string $body, string $contentType = 'application/json'): TgClient
    {
        $delegate = Mockery::mock(DelegateHttpClient::class);
        $delegate->shouldReceive('request')->once()->andReturnUsing(
            fn (Request $request) => new Response('1.1', 200, null, ['content-type' => $contentType], $body, $request),
        );
        return new TgClient('fake.test', 9503, 'test', 'test', new TelegramCache(false), new HttpClient($delegate, []));
    }
}
