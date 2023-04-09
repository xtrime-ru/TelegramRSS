<?php

namespace TelegramRSS\Server;

use Amp\Http\Server\Driver\ConnectionLimitingClientFactory;
use Amp\Http\Server\Driver\ConnectionLimitingServerSocketFactory;
use Amp\Http\Server\Driver\DefaultHttpDriverFactory;
use Amp\Http\Server\Driver\SocketClientFactory;
use Amp\Http\Server\SocketHttpServer;
use Amp\Socket\InternetAddress;
use Amp\Sync\LocalSemaphore;
use TelegramRSS\TgClient;
use TelegramRSS\Config;
use TelegramRSS\Logger;

use function Amp\trapSignal;


final class Server
{
    public const JSON_HEADER = ['Content-Type' => 'application/json;charset=utf-8'];

    /**
     * Server constructor.
     * @param TgClient $client
     * @param string $address
     * @param string $port
     */
    public function __construct(TgClient $client, string $address = '', string $port = '')
    {
        $server = new SocketHttpServer(
            logger: Logger::getInstance(),
            serverSocketFactory: new ConnectionLimitingServerSocketFactory(new LocalSemaphore(1000)),
            clientFactory: new SocketClientFactory(Logger::getInstance()),
            httpDriverFactory: new DefaultHttpDriverFactory(
                logger: Logger::getInstance(),
                streamTimeout: 600,
                connectionTimeout: 5,
                bodySizeLimit: 5 * (1024 ** 3), //5Gb
            )
        );

        $server->expose(
            new InternetAddress(
                $address ?: Config::getInstance()->get('server.address'),
                $port ?: Config::getInstance()->get('server.port'),
            )
        );

        $errorHandler = new ErrorResponse();
        $server->start((new Router($client, $server, $errorHandler))->getRouter(), $errorHandler);

        self::registerShutdown($server);
    }

    /**
     * Stop the server gracefully when SIGINT is received.
     * This is technically optional, but it is best to call Server::stop().
     *
     *
     */
    private static function registerShutdown(SocketHttpServer $server)
    {
        if (defined('SIGINT')) {
            // Await SIGINT or SIGTERM to be received.
            $signal = trapSignal([\SIGINT, \SIGTERM]);
            info(\sprintf("Received signal %d, stopping HTTP server", $signal));
            $server->stop();
        }
    }

}