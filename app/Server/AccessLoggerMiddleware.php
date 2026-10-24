<?php

namespace TelegramRSS\Server;

use Amp\CancelledException;
use Amp\Http\Server\Middleware;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Psr\Log\LoggerInterface as PsrLogger;
use Psr\Log\LogLevel;
use TelegramRSS\AccessControl\AccessControl;
use TelegramRSS\Cache\CacheContext;
use TelegramRSS\Cache\CacheStatus;

class AccessLoggerMiddleware implements Middleware
{
    public function __construct(
        private readonly PsrLogger $logger,
        private readonly AccessControl $accessControl,
    ) {
    }

    public function handleRequest(Request $request, RequestHandler $requestHandler): Response
    {
        $remote = Server::getClientIp($request);

        $method = $request->getMethod();
        $uri = (string)$request->getUri();
        $protocolVersion = $request->getProtocolVersion();

        $authorizationHeader = $request->getHeader('authorization');
        $clientType = str_starts_with($request->getUri()->getPath(), '/media/') ? 'media' : 'default';
        $username = $this->accessControl->checkAuth($authorizationHeader);
        $user = $this->accessControl->getOrCreateUser($username ?: $remote, $clientType);
        $request->setAttribute('user', $user);

        $context = [
            'method' => $method,
            'uri' => $uri,
            'user_agent' => $request->getHeader('user-agent'),
            'referer' => $request->getHeader('referer'),
            'remote' => $remote,
            'username' => $username,
            'rpm_limit' => $user->rpmLimit,
            'errors' => count($user->errors),
            'errors_limit' => $user->errorsLimit,
            'previous_ban' => $user->banLastDuration,
            'banned' => (int)$user->isBanned(),
        ];

        try {
            $response = $requestHandler->handleRequest($request);
        } catch (CancelledException $exception) {
            $this->logger->log(
                LogLevel::NOTICE,
                \sprintf('"%s %s" 499 "Client Closed Request" HTTP/%s %s', $method, $uri, $protocolVersion, $remote),
                ['request' => $context, 'response' => ['status' => 499, 'reason' => 'Client Closed Request']],
            );
            throw $exception;
        } catch (\Throwable $exception) {
            $this->logger->warning(
                \sprintf(
                    'Client exception for "%s %s" HTTP/%s %s',
                    $method,
                    $uri,
                    $protocolVersion,
                    $remote,
                ),
                $context
            );

            throw $exception;
        }

        $status = $response->getStatus();
        $reason = $response->getReason();
        $context['errors'] = count($user->errors);
        $context['previous_ban'] = $user->banLastDuration;
        $context['banned'] = (int) $user->isBanned();
        $context['rate_limit_policy'] = $user->rpmLimit === -1 ? 'no_limit' : 'sliding_window';
        $rateLimit = $user->getRateLimit();
        $context['rate_limit_remaining'] = $rateLimit?->getRemainingTokens();
        $context['rate_limit_used'] = $rateLimit ? $rateLimit->getLimit() - $rateLimit->getRemainingTokens() : null;
        $context['rate_limit_retry_after'] = $user->rateRetryAfter;
        $context += CacheContext::fromRequest($request)?->logFields()
            ?? ['cache_status' => CacheStatus::BYPASS->value, 'message_source' => null, 'cache_ttl' => null];

        $context = [
            'request' => $context,
            'response' => [
                'status' => $status,
                'reason' => $reason,
            ],
        ];

        $level = $status < 400 ? LogLevel::INFO : LogLevel::NOTICE;

        $this->logger->log(
            $level,
            \sprintf(
                '"%s %s" %d "%s" HTTP/%s %s',
                $method,
                $uri,
                $status,
                $reason,
                $protocolVersion,
                $remote,
            ),
            $context
        );

        return $response;
    }
}
