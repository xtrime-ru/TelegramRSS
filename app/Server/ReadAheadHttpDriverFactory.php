<?php

namespace TelegramRSS\Server;

use Amp\ByteStream\ReadableIterableStream;
use Amp\ByteStream\ReadableStream;
use Amp\ByteStream\WritableStream;
use Amp\DeferredCancellation;
use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Driver\HttpDriver;
use Amp\Http\Server\Driver\HttpDriverFactory;
use Amp\Http\Server\ErrorHandler;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\RequestHandler\ClosureRequestHandler;

/** Keeps reading one chunk ahead so disconnects are detected while a handler waits. */
final class ReadAheadHttpDriverFactory implements HttpDriverFactory
{
    public function __construct(private readonly HttpDriverFactory $factory)
    {
    }

    public function getApplicationLayerProtocols(): array
    {
        return $this->factory->getApplicationLayerProtocols();
    }

    public function createHttpDriver(RequestHandler $requestHandler, ErrorHandler $errorHandler, Client $client): HttpDriver
    {
        $disconnect = new DeferredCancellation;
        $client->onClose(static fn () => $disconnect->cancel());
        if ($client->isClosed()) {
            $disconnect->cancel();
        }
        $handler = new ClosureRequestHandler(static function (Request $request) use ($requestHandler, $disconnect) {
            if ($request->getProtocolVersion() !== '2') {
                $request->setCancellation($disconnect->getCancellation());
            }
            $request->getCancellation()->throwIfRequested();
            return $requestHandler->handleRequest($request);
        });
        return new class($this->factory->createHttpDriver($handler, $errorHandler, $client)) implements HttpDriver {
            public function __construct(private readonly HttpDriver $driver)
            {
            }

            public function getApplicationLayerProtocols(): array
            {
                return $this->driver->getApplicationLayerProtocols();
            }

            public function getPendingRequestCount(): int
            {
                return $this->driver->getPendingRequestCount();
            }

            public function stop(): void
            {
                $this->driver->stop();
            }

            public function handleClient(Client $client, ReadableStream $readableStream, WritableStream $writableStream): void
            {
                $input = new ReadableIterableStream((static function () use ($readableStream): \Generator {
                    while (($chunk = $readableStream->read()) !== null) {
                        yield $chunk;
                    }
                })());
                try {
                    $this->driver->handleClient($client, $input, $writableStream);
                } finally {
                    $client->close();
                    $input->close();
                }
            }
        };
    }
}
