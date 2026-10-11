<?php

namespace TelegramRSS\Tests;

use Amp\File\Driver\BlockingFilesystemDriver;
use Amp\File\Filesystem;
use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\HttpServer;
use Amp\Http\Server\Request;
use Amp\Http\Server\StaticContent\DocumentRoot;
use Closure;
use League\Uri\Http;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

use function Amp\ByteStream\buffer;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class StaticContentTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function testStaticContentWithFile4PreservesStreamsRangesAndConditionalRequests(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'tg-static-');
        self::assertIsString($path);
        $filesystem = new Filesystem(new BlockingFilesystemDriver());
        $start = $stop = null;
        try {
            $file = $filesystem->openFile($path, 'wb');
            try {
                $file->write('0123456789');
            } finally {
                $file->close();
            }
            $server = Mockery::mock(HttpServer::class);
            $server->shouldReceive('onStart')->once()->andReturnUsing(static function (Closure $callback) use (&$start): void {
                $start = $callback;
            });
            $server->shouldReceive('onStop')->once()->andReturnUsing(static function (Closure $callback) use (&$stop): void {
                $stop = $callback;
            });
            $root = new DocumentRoot($server, new DefaultErrorHandler(), dirname($path), $filesystem);
            $root->setBufferedFileLimit(0);
            $start();
            $request = fn (array $headers = []) => new Request(Mockery::mock(Client::class), 'GET',
                Http::new('https://example.test/' . basename($path)), $headers);
            $full = $root->handleRequest($request());
            $range = $root->handleRequest($request(['Range' => 'bytes=2-4']));
            self::assertSame(200, $full->getStatus());
            self::assertSame('0123456789', buffer($full->getBody()));
            self::assertSame(206, $range->getStatus());
            self::assertSame('bytes 2-4/10', $range->getHeader('Content-Range'));
            self::assertSame('234', buffer($range->getBody()));
            self::assertNotNull($full->getHeader('ETag'));
            self::assertNotNull($full->getHeader('Last-Modified'));
            self::assertSame(304, $root->handleRequest($request(['If-None-Match' => $full->getHeader('ETag')]))->getStatus());
        } finally {
            try {
                $stop?->__invoke();
            } finally {
                unlink($path);
            }
        }
    }
}
