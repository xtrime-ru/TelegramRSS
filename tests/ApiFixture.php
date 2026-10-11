<?php

namespace TelegramRSS\Tests;

use Amp\Cancellation;
use Amp\Http\Client\Request as ApiRequest;
use Amp\Http\Client\Response as ApiResponse;
use RuntimeException;

use function Amp\ByteStream\buffer;

final class ApiFixture
{
    /** @var list<array{method: string, params: array<array-key, mixed>|string, headers: array<string, list<string>>}> */
    public array $calls = [];
    /** @var array<string, \Closure(ApiRequest, array<array-key, mixed>|string): ApiResponse> */
    public array $handlers = [];
    public int $version = 1;

    public function request(ApiRequest $request, Cancellation $cancellation): ApiResponse
    {
        $method = basename($request->getUri()->getPath());
        $params = json_decode(buffer($request->getBody()->getContent()), true);
        $this->calls[] = ['method' => $method, 'params' => $params, 'headers' => $request->getHeaders()];
        if (isset($this->handlers[$method])) {
            return ($this->handlers[$method])($request, $params);
        }
        if ($method === 'getMedia' || $method === 'getMediaPreview') {
            return new ApiResponse('1.1', 206, null,
                ['content-type' => 'application/octet-stream', 'content-range' => 'bytes 0-2/10'],
                $method === 'getMedia' ? 'abc' : 'xyz', $request);
        }
        $response = match ($method) {
            'getInfo' => ['type' => 'channel'],
            'getSelf' => ['premium' => true],
            'getFullInfo' => ['Chat' => ['title' => 'Test channel'], 'full' => ['about' => 'Test feed']],
            'getDownloadInfo' => ['size' => 10, 'mime' => 'video/mp4', 'name' => 'video', 'ext' => '.mp4'],
            'getHistoryHtml' => ['messages' => [$this->message(7)], 'count' => 1, 'chats' => [], 'users' => []],
            'getMessages' => ['messages' => [$this->message((int) $params['id'][0])]],
            default => throw new RuntimeException('Unexpected API method: ' . $method),
        };
        return $this->response($request, $response);
    }

    /**
     * @return array{_: string, id: int, date: int, message: string, peer_id: array{_: string, channel_id: int}, media: array<string, mixed>}
     */
    public function message(int $id): array
    {
        return ['_' => 'message', 'id' => $id, 'date' => 1700000000, 'message' => 'Version ' . $this->version,
            'peer_id' => ['_' => 'peerChannel', 'channel_id' => 123],
            'media' => ['_' => 'messageMediaDocument', 'document' => ['_' => 'document', 'id' => 42,
                'mime_type' => 'video/mp4', 'file_reference' => ['_' => 'bytes', 'bytes' => 'AAEC']]]];
    }

    /**
     * @param array{limit: int, add_offset: int, offset_id: int, ...} $data
     * @return array{messages: list<array<string, mixed>>, count: int, chats: list<array{title: string}>, users: list<array{id: int}>}
     */
    public function history(array $data, int $count = 250): array
    {
        // Deliberately leave gaps between IDs: pagination uses positions, never ID subtraction.
        $messages = array_map(fn ($position) => $this->message(1000 - 3 * $position), range(0, $count - 1));
        if ($data['offset_id']) {
            $messages = array_values(array_filter($messages, fn ($message) => $message['id'] < $data['offset_id']));
        }
        return ['messages' => array_slice($messages, $data['add_offset'], $data['limit']),
            'count' => $count, 'chats' => [['title' => 'History channel']], 'users' => [['id' => 123]]];
    }

    /**
     * @param array<array-key, mixed> $value
     */
    public function response(ApiRequest $request, array $value): ApiResponse
    {
        return new ApiResponse('1.1', 200, null, ['content-type' => 'application/json'],
            json_encode(['response' => $value], JSON_THROW_ON_ERROR), $request);
    }

    public function error(ApiRequest $request, string $message, int $code = 400): ApiResponse
    {
        return new ApiResponse('1.1', 400, null, ['content-type' => 'application/json'],
            json_encode(['errors' => [['message' => $message, 'code' => $code]]], JSON_THROW_ON_ERROR), $request);
    }

    public function count(string $method): int
    {
        return count(array_filter($this->calls, fn ($c) => $c['method'] === $method));
    }
}
