<?php

namespace TelegramRSS\Server;

use Amp\Http\HttpStatus;
use Amp\Http\Server\ErrorHandler;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;

class ErrorResponse implements ErrorHandler
{

    public function handleError(int $status, ?string $reason = null, ?Request $request = null): Response
    {
        return self::customError($status, ['code' => $status, 'message' => $reason]);
    }

    public static function customError(int $status, array $errors) {
        if ($status < 300 || $status > 599) {
            $status = HttpStatus::BAD_REQUEST;
        }
        $response = new Response(
            $status,
            Server::JSON_HEADER,
            json_encode(
                [
                    'success' => false,
                    'errors' => $errors,
                ],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
            ) . "\n"
        );
        $response->setStatus($status, $errors[array_key_last($errors)]);
        return $response;
    }
}