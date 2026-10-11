<?php

namespace TelegramRSS\Server;

use Amp\Http\Server\ErrorHandler;
use Amp\Http\Server\SocketHttpServer;
use Amp\Http\Server\StaticContent\DocumentRoot;
use TelegramRSS\AccessControl\AccessControl;
use TelegramRSS\Controller\IconController;
use TelegramRSS\TgClient;
use TelegramRSS\Controller\JsonController;
use TelegramRSS\Controller\MediaController;
use TelegramRSS\Controller\RequestValidatorMiddleware;
use TelegramRSS\Controller\RSSController;
use TelegramRSS\Logger;

use function Amp\Http\Server\Middleware\stackMiddleware;

class Router
{
    private \Amp\Http\Server\Router $router;

    public function __construct(TgClient $client, SocketHttpServer $server, ErrorHandler $errorHandler )
    {
        $this->router = new \Amp\Http\Server\Router(httpServer: $server, logger: Logger::getInstance(), errorHandler: $errorHandler);
        $this->setRoutes($client);
        $this->router->setFallback(new DocumentRoot($server, $errorHandler, ROOT_DIR . '/public'));
    }

    public function getRouter(): \Amp\Http\Server\Router
    {
        return $this->router;
    }

    private function setRoutes(TgClient $client): void
    {
        $accessControl = new AccessControl();
        $middlewares = [
            new AccessLoggerMiddleware(Logger::getInstance(), $accessControl),
            new AuthorizationMiddleware(),
        ];
        $validator = new RequestValidatorMiddleware($client, true);
        /** @var \Closure(string): list<\Amp\Http\Server\Middleware> $cached */
        $cached = fn (string $format): array => [...$middlewares, $validator, new CacheMiddleware($client, $validator, $format)];
        foreach (['GET', 'POST'] as $method) {
            $this->router->addRoute($method, '/json/{channel}[/[{page}[/]]]', stackMiddleware(new JsonController($client), ...$cached('json')));
            $this->router->addRoute($method, '/rss/{channel}[/[{page}[/]]]', stackMiddleware(new RSSController($client), ...$cached('rss')));
            $this->router->addRoute($method, '/media/{channel}/{message_id}[/[{preview}[/[{filename}]]]]', stackMiddleware(new MediaController($client), ...$cached('media')));
            $this->router->addRoute($method, '/icon/{channel}[/[{filename}]]', stackMiddleware(new IconController($client), ...[...$middlewares, new RequestValidatorMiddleware($client)]));
        }
    }


}
