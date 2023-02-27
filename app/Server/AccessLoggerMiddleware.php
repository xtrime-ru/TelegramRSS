<?php

namespace TelegramRSS\Server;

use Amp\Http\Server\Middleware;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Psr\Log\LoggerInterface as PsrLogger;
use Psr\Log\LogLevel;
use TelegramRSS\AccessControl\AccessControl;

class AccessLoggerMiddleware implements Middleware
{
    public function __construct(
        private readonly PsrLogger $logger,
        private readonly AccessControl $accessControl,
    ) {
    }

    public function handleRequest(Request $request, RequestHandler $requestHandler): Response
    {
        $client = $request->getClient();

        $local = $client->getLocalAddress()->toString();
        $remote = $client->getRemoteAddress()->toString();
        $method = $request->getMethod();
        $uri = (string) $request->getUri();
        $protocolVersion = $request->getProtocolVersion();

        $user = $this->accessControl->getOrCreateUser(
            explode(':', $remote)[0],
            str_contains($uri, '/media/') ? 'media' : 'default'
        );

        $context = [
            'method' => $method,
            'uri' => $uri,
            'user_agent' => $request->getHeader('user-agent'),
            'remote' => $remote,
            'rpm' => $user->rpm,
            'rpm_limit' => $user->rpmLimit,
            'errors' => $user->errors,
            'errors_limit' => $user->errorsLimit,
            'previous_ban' => $user->banLastDuration,
            'banned' => (int)$user->isBanned(),
        ];

        try {
            $response = $requestHandler->handleRequest($request);
        } catch (\Throwable $exception) {
            $this->logger->warning(\sprintf(
                'Client exception for "%s %s" HTTP/%s %s',
                $method,
                $uri,
                $protocolVersion,
                $remote,
            ), $context);

            throw $exception;
        }

        $status = $response->getStatus();
        $reason = $response->getReason();

        $context = [
            'request' => $context,
            'response' => [
                'status' => $status,
                'reason' => $reason,
            ]
        ];

        $level = $status < 400 ? LogLevel::INFO : LogLevel::NOTICE;

        $this->logger->log($level, \sprintf(
            '"%s %s" %d "%s" HTTP/%s %s on %s',
            $method,
            $uri,
            $status,
            $reason,
            $protocolVersion,
            $remote,
            $local,
        ), $context);

        return $response;
    }
}
