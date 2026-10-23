<?php

namespace TelegramRSS\Server;

use Amp\Http\HttpStatus;
use Amp\Http\Server\ClientException;
use Amp\Http\Server\Middleware;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use TelegramRSS\AccessControl\User;
use TelegramRSS\TgClient;
use TelegramRSS\Config;
use TelegramRSS\Cache\FloodWaitException;

class AuthorizationMiddleware implements Middleware
{
    /** @var array<string, null> */
    private array $ipBlacklist = [];
    private int $selfIp;
    private string $forbibbenRefererRegex;

    public function __construct()
    {
        $fileName = (string)Config::getInstance()->get('access.ip_blacklist', "");
        $filePath = ROOT_DIR . "/{$fileName}";
        if (is_file($filePath)) {
            $contents = file_get_contents($filePath);
            if ($contents === false) {
                throw new \RuntimeException('Failed to read IP blacklist: ' . $filePath);
            }
            $ips = array_filter(explode("\n", $contents));
            $this->ipBlacklist = array_fill_keys($ips, null);
        }
        $this->selfIp = ip2long(getHostByName(php_uname('n')));
        $this->forbibbenRefererRegex = (string)Config::getInstance()->get('access.forbidden_referer_regex');
    }

    public function handleRequest(Request $request, RequestHandler $requestHandler): Response
    {
        $host = Server::getClientIp($request);
        $user = null;
        $retryAfter = null;
        $cacheDetail = 'access-denied';

        try {
            if (!$this->isIpAllowed($host)) {
                throw new ClientException($request->getClient(), 'Your ip is not allowed: ' . $host , HttpStatus::FORBIDDEN);
            }

            /**
             * @var User $user
             * @see AccessLoggerMiddleware::handleRequest
             * */
            /** @var \TelegramRSS\AccessControl\User $user */
        $user = $request->getAttribute('user');
            $referer = $request->getHeader('referer');
            $isStreaming = $request->hasHeader('range');
            if ($referer && $this->forbibbenRefererRegex && !$isStreaming && preg_match("/{$this->forbibbenRefererRegex}/i", $referer)) {
                throw new ClientException($request->getClient(), 'Referer forbidden: ' . $referer, HttpStatus::FORBIDDEN);
            }

            if ($user->isBanned()) {
                $cacheDetail = 'ban';
                $retryAfter = $user->getBanRemainingSeconds();
                throw new ClientException($request->getClient(), "Time to unlock access: {$user->getBanDuration()}", HttpStatus::TOO_MANY_REQUESTS);
            }
            $user->addRequest();
            if ($user->isRateLimited()) {
                $cacheDetail = 'rate-limit';
                $retryAfter = $user->rateRetryAfter;
                throw new ClientException($request->getClient(), 'Too many requests', HttpStatus::TOO_MANY_REQUESTS);
            }
        } catch (\Throwable $e) {
            $errors = array_merge($user ? $user->errors : [], [$e->getMessage()]);
            $response = ErrorResponse::customError($e->getCode(), $errors);
            $response->setHeader('Cache-Status', 'TelegramRSS; fwd=bypass; detail=' . $cacheDetail);
            if ($retryAfter !== null) {
                $response->setHeader('Retry-After', (string) $retryAfter);
            }
            return $response;
        }

        try {
            $response = $requestHandler->handleRequest($request);
        } catch (\Throwable $e) {
            if (!self::isErrorAllowed($e->getMessage()) && $user !== null) {
                $user->addError($e->getMessage(), (string)$request->getUri());
            }
            $errors = array_merge($user->errors, [$e->getMessage()]);
            $response = ErrorResponse::customError($e->getCode(), $errors);
            $cacheDetail = 'error';
            if ($e instanceof FloodWaitException) {
                $cacheDetail = 'flood-wait';
                $response->setHeader('Retry-After', (string) $e->retryAfter);
            }
            $response->setHeader('Cache-Status', 'TelegramRSS; fwd=bypass; detail=' . $cacheDetail);
            return $response;
        }

        if (!$response->hasHeader('Cache-Status')) {
            $response->setHeader('Cache-Status', 'TelegramRSS; fwd=bypass');
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
