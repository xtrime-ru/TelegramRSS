<?php

namespace TelegramRSS\Controller;

use Amp\CancelledException;
use Amp\Http\Server\ClientException;
use Amp\Http\Server\Middleware;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\Router;
use TelegramRSS\AccessControl\ForbiddenPeers;
use TelegramRSS\Cache\TelegramCache;
use TelegramRSS\Cache\CacheContext;
use TelegramRSS\Config;
use TelegramRSS\TgClient;

class RequestValidatorMiddleware implements Middleware
{

    public function __construct(
        private readonly TgClient $tgClient,
        private readonly bool $deferRemoteValidation = false,
    ) {
    }

    public function handleRequest(Request $request, RequestHandler $requestHandler): Response
    {
        $channel = TelegramCache::normalizeChannel(self::getChannel($request));
        $request->setAttribute('channel', $channel);
        try {
            $this->validateLocalPeer($request);
            if (!$this->deferRemoteValidation) {
                $this->validateRemotePeer($request);
            }
            return $requestHandler->handleRequest($request);
        } catch (CancelledException $exception) {
            throw $exception;
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
        $this->validateLocalPeer($request);
        $this->validateRemotePeer($request);
    }

    public function validateLocalPeer(Request $request): void
    {
        $client = $request->getClient();
        /** @var string $channel */
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
    }

    public function validateRemotePeer(Request $request, ?CacheContext $context = null): void
    {
        $client = $request->getClient();
        /** @var string $channel */
        $channel = $request->getAttribute('channel');
        $info = $this->tgClient->getInfo($channel, context: $context, cancellation: $request->getCancellation());
        $isChannel = in_array($info['type'], ['channel', 'supergroup']);
        if (
            Config::getInstance()->get('access.only_public_channels') &&
            !$isChannel
        ) {
            throw new ClientException($client, 'This is not a public channel', 403);
        }
    }
}
