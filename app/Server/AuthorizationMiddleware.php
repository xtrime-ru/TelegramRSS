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
    private array $ipBlacklist = [];
    private int $selfIp;
    private AccessControl $accessControl;
    private string $forbibbenRefererRegex;

    public function __construct(AccessControl $accessControl)
    {
        $fileName = (string)Config::getInstance()->get('access.ip_blacklist', "");
        $filePath = ROOT_DIR . "/{$fileName}";
        if (is_file($filePath)) {
            $ips = array_filter(
                explode("\n", file_get_contents($filePath) ?: "")
            );
            $this->ipBlacklist = array_fill_keys($ips, null);
        }
        $this->selfIp = ip2long(getHostByName(php_uname('n')));
        $this->accessControl = $accessControl;
        $this->forbibbenRefererRegex = (string)Config::getInstance()->get('access.forbidden_referer_regex');
    }

    public function handleRequest(Request $request, RequestHandler $requestHandler): Response
    {
        $host = Server::getClientIp($request);
        $user = null;

        try {
            if (!$this->isIpAllowed($host)) {
                throw new ClientException($request->getClient(), 'Your ip is not allowed: ' . $host , HttpStatus::FORBIDDEN);
            }

            $user = $this->accessControl->getOrCreateUser(
                $host,
                str_contains($request->getUri(), '/media/') ? 'media' : 'default'
            );
            $user->addRequest($request->getUri());

            $referer = $request->getHeader('referer');
            $isStreaming = $request->hasHeader('range');
            if ($referer && $this->forbibbenRefererRegex && !$isStreaming && preg_match("/{$this->forbibbenRefererRegex}/i", $referer)) {
                throw new ClientException($request->getClient(), 'Referer forbidden: ' . $referer, HttpStatus::FORBIDDEN);
            }

            if ($user->isBanned()) {
                throw new ClientException($request->getClient(), "Time to unlock access: {$user->getBanDuration()}", HttpStatus::FORBIDDEN);
            }
        } catch (\Throwable $e) {
            $errors = array_merge($user ? $user->errors : [], [$e->getMessage()]);
            return ErrorResponse::customError($e->getCode(), $errors);
        }

        try {
            $response = $requestHandler->handleRequest($request);
        } catch (\Throwable $e) {
            if (!self::isErrorAllowed($e->getMessage()) && $user !== null) {
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

        if ($this->ipBlacklist && array_key_exists($host, $this->ipBlacklist)) {
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