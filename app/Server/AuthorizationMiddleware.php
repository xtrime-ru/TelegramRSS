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

class AuthorizationMiddleware implements Middleware
{
    private array $ipWhitelist;
    private int $selfIp;
    private AccessControl $accessControl;
    private string $forbibbenRefererRegex;

    public function __construct(AccessControl $accessControl)
    {
        $this->ipWhitelist = (array)Config::getInstance()->get('api.ip_whitelist', []);
        $this->selfIp = ip2long(getHostByName(php_uname('n')));
        $this->accessControl = $accessControl;
        $this->forbibbenRefererRegex = (string)Config::getInstance()->get('access.forbidden_referer_regex');
    }

    public function handleRequest(Request $request, RequestHandler $requestHandler): Response
    {
        $host = Server::getClientIp($request);

        $user = $this->accessControl->getOrCreateUser(
            $host,
            str_contains($request->getUri(), '/media/') ? 'media' : 'default'
        );
        $user->addRequest($request->getUri());

        try {

            $referer = $request->getHeader('referer');
            $isStreaming = $request->hasHeader('range');
            if ($referer && $this->forbibbenRefererRegex && !$isStreaming && preg_match("/{$this->forbibbenRefererRegex}/i", $referer)) {
                throw new ClientException($request->getClient(), 'Referer forbidden: ' . $referer, HttpStatus::FORBIDDEN);
            }

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
            if (!self::isErrorAllowed($e->getMessage())) {
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

    private static function isErrorAllowed(string $error):bool {
        return
            $error == TgClient::MESSAGE_CLIENT_UNAVAILABLE
            || str_starts_with($error, 'FLOOD_WAIT')
        ;
    }
}