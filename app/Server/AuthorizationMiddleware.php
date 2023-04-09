<?php

namespace TelegramRSS\Server;

use Amp\Http\HttpStatus;
use Amp\Http\Server\ClientException;
use Amp\Http\Server\Middleware;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use TelegramRSS\AccessControl\AccessControl;
use TelegramRSS\TgClient;
use TelegramRSS\Config;
use TelegramRSS\Controller\MediaController;

class AuthorizationMiddleware implements Middleware
{
    private array $ipWhitelist;
    private int $selfIp;
    private AccessControl $accessControl;

    public function __construct(AccessControl $accessControl)
    {
        $this->ipWhitelist = (array)Config::getInstance()->get('api.ip_whitelist', []);
        $this->selfIp = ip2long(getHostByName(php_uname('n')));
        $this->accessControl = $accessControl;
    }

    public function handleRequest(Request $request, RequestHandler $requestHandler): Response
    {
        $host = $request->getHeader('x-real-ip')
            ??
            $request->getHeader('x-forwarded-for')
            ??
            explode(':', $request->getClient()->getRemoteAddress()->toString())[0]
        ;

        $user = $this->accessControl->getOrCreateUser(
            $host,
            str_contains($request->getUri(), '/media/') ? 'media' : 'default'
        );
        $user->addRequest($request->getUri());

        try {
            if (!$this->isIpAllowed($host)) {
                throw new ClientException($request->getClient(), 'Your host is not allowed: ' . $host , HttpStatus::FORBIDDEN);
            }

            if ($user->isBanned()) {
                throw new ClientException($request->getClient(), "Time to unlock access: {$user->getBanDuration()}", HttpStatus::FORBIDDEN);
            }
        } catch (\Throwable $e) {
            $errors = array_merge($user->errors, [$e->getMessage()]);
            return ErrorResponse::customError($e->getCode(), $errors);
        }

        try {
            $response = $requestHandler->handleRequest($request);
        } catch (\Throwable $e) {
            if ($e->getMessage() !== TgClient::MESSAGE_CLIENT_UNAVAILABLE) {
                $user->addError($e->getMessage(), (string)$request->getUri());
            }
            $errors = array_merge($user->errors, [$e->getMessage()]);
            return ErrorResponse::customError($e->getCode(), $errors);
        }

        return $response;
    }

    private function isIpAllowed(string $host): bool
    {
        global $options;
        if ($options['docker']) {
            $isSameNetwork = abs(ip2long($host) - $this->selfIp) < 256;
            if ($isSameNetwork) {
                return true;
            }
        }

        if ($this->ipWhitelist && !in_array($host, $this->ipWhitelist, true)) {
            return false;
        }
        return true;
    }
}