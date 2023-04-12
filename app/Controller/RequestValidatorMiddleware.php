<?php

namespace TelegramRSS\Controller;

use Amp\Http\Server\ClientException;
use Amp\Http\Server\Middleware;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\Router;
use TelegramRSS\AccessControl\ForbiddenPeers;
use TelegramRSS\Config;
use TelegramRSS\TgClient;

class RequestValidatorMiddleware implements Middleware
{

    public function __construct(
        private readonly TgClient $tgClient,
    ) {
    }

    public function handleRequest(Request $request, RequestHandler $requestHandler): Response
    {
        $channel = self::getChannel($request);
        $request->setAttribute('channel', $channel);
        try {
            $this->validatePeer($request);
            return $requestHandler->handleRequest($request);
        } catch (\Throwable $exception) {
            ForbiddenPeers::add($channel, $exception->getMessage());
            throw $exception;
        }
    }

    public static function getChannel(Request $request): string
    {
        return $request->getAttribute(Router::class)['channel']
            ?? throw new ClientException($request->getClient(), 'Need to specify channel');
    }


    public function validatePeer(Request $request): void
    {
        $client = $request->getClient();
        $channel = $request->getAttribute('channel');

        if (Config::getInstance()->get('access.only_public_channels')) {
            if (preg_match('/[^\w\-@]/', $channel)) {
                throw new ClientException($client, 'WRONG NAME', 404);
            }

            if (preg_match('/bot$/i', $channel)) {
                throw new ClientException($client, 'BOTS NOT ALLOWED', 403);
            }
        }

        $error = ForbiddenPeers::check($channel);
        if ($error !== null) {
            throw new ClientException($client, $error, 403);
        }

        $info = $this->tgClient->getInfo($channel);
        $isChannel = in_array($info['type'], ['channel', 'supergroup']);
        if (
            Config::getInstance()->get('access.only_public_channels') &&
            !$isChannel
        ) {
            throw new ClientException($client, 'This is not a public channel', 403);
        }
    }
}