<?php

namespace TelegramRSS;


use Amp\Cancellation;
use Amp\CancelledException;
use Amp\Http\Client\Connection\DefaultConnectionFactory;
use Amp\Http\Client\Connection\UnlimitedConnectionPool;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Interceptor\AddRequestHeader;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use Amp\Http\HttpResponse;
use Amp\Http\HttpStatus;
use Amp\Http\Server\Response as ServerResponse;
use Amp\Socket\DnsSocketConnector;
use TelegramRSS\Cache\TelegramCache;
use TelegramRSS\Cache\CacheContext;
use TelegramRSS\Cache\CacheStatus;
use TelegramRSS\Cache\CacheResult;
use UnexpectedValueException;

use function Amp\async;
use function Amp\delay;
use function Amp\Future\awaitAll;

/**
 * @phpstan-type HistoryQuery array{peer?: string, limit?: int, offset_id?: int, offset_date?: int, add_offset?: int, max_id?: int, min_id?: int, hash?: int|list<int>}
 * @phpstan-type History array{messages?: list<array<string, mixed>>, count?: int, chats?: list<array<string, mixed>>, users?: list<array<string, mixed>>, sponsored_messages?: list<array<string, mixed>>, ...}
 * @phpstan-type Message array{_: 'message'|'messageService', id: int, message?: string, date?: int, edit_date?: int, media?: array<string, mixed>, ...}
 * @phpstan-type Headers array<string, string|list<string>>
 * @phpstan-type MediaQuery array{peer?: string, id?: int|list<int>, ...}
 */
class TgClient
{
    private const RETRY = 2;
    private const RETRY_INTERVAL = 0;
    private ?bool $isPremium = null;
    public const MESSAGE_CLIENT_UNAVAILABLE = 'Telegram connection error...';
    private string $apiUrl;
    private HttpClient $client;
    private readonly TelegramCache $cache;

    /**
     * Client constructor.
     * @param HttpClient|null $httpClient Optional HTTP transport for isolated tests.
     */
    public function __construct(string $address = '', int $port = 0, string $username = '', string $password = '', ?TelegramCache $cache = null, ?HttpClient $httpClient = null)
    {
        $this->cache = $cache ?? new TelegramCache(...Config::getInstance()->get('cache', []));
        $address = $address ?: Config::getInstance()->get('client.address');
        $port = $port ?: Config::getInstance()->get('client.port');
        $username = $username ?: Config::getInstance()->get('client.username');
        $password = $password ?: Config::getInstance()->get('client.password');
        $this->apiUrl = "http://$address:$port";
        if ($httpClient !== null) {
            $this->client = $httpClient;
            return;
        }
        $builder = (new HttpClientBuilder())
            ->usingPool(new UnlimitedConnectionPool(new DefaultConnectionFactory(new DnsSocketConnector())))
            ->retry(0)
        ;

        if ($username || $password) {
            $base64 = base64_encode("{$username}:{$password}");
            $builder = $builder->intercept(new AddRequestHeader('Authorization', "Basic $base64"));
        }

        $this->client = $builder->build();
    }

    /**
     * @param HistoryQuery $data
     * @return History
     */
    public function getHistoryHtml(array $data, ?CacheContext $context = null, ?Cancellation $cancellation = null): array
    {
        $cancellation?->throwIfRequested();
        $data = array_merge(
            [
                'peer' => '',
                'limit' => 10,
                'offset_id' => 0,
                'offset_date' => 0,
                'add_offset' => 0,
                'max_id' => 0,
                'min_id' => 0,
                'hash' => [],
            ],
            $data
        );
        $data['peer'] = TelegramCache::normalizeChannel((string) $data['peer']);
        ksort($data);
        if (!$this->usesHistoryWindow($data) || $context?->cacheResponse === false) {
            return $this->historyResponse($this->loadHistory($data, false, $context, cancellation: $cancellation), $context);
        }

        $window = $data;
        $window['limit'] = $this->cache->historyWindowSize;
        $window['add_offset'] = 0;
        $window['hash'] = [];
        $result = $this->loadHistory($window, true, $context, cancellation: $cancellation);
        $history = $result->value;
        $messages = $history['messages'] ?? [];
        $covered = count($messages) >= $data['add_offset'] + $data['limit'];
        $atEnd = isset($history['count']) && $history['count'] <= count($messages);
        if (!$covered && !$atEnd) {
            // A partial snapshot cannot answer this range. Do not cache the feed or use stale fallback.
            $context?->bypassResponseCache();
            if ($data === $window && in_array($result->status, [CacheStatus::MISS, CacheStatus::COALESCED], true)) {
                // This fresh load already used the exact original query; do not repeat it.
                return $this->historyResponse($result->withStatus(CacheStatus::BYPASS), $context);
            }
            return $this->historyResponse($this->loadHistory($data, false, $context, cancellation: $cancellation), $context);
        }
        $history['messages'] = array_slice($messages, (int) $data['add_offset'], (int) $data['limit']);
        return $this->historyResponse($result, $context, $history);
    }

    /**
     * @param HistoryQuery $data
     */
    public function usesHistoryWindow(array $data): bool
    {
        $limit = $data['limit'] ?? 10;
        $offset = $data['add_offset'] ?? 0;
        return $this->cache->enabled && $limit > 0 && $offset >= 0
            && $offset <= $this->cache->historyWindowSize - $limit
            && ($data['offset_id'] ?? 0) == 0 && ($data['offset_date'] ?? 0) == 0
            && ($data['max_id'] ?? 0) == 0 && ($data['min_id'] ?? 0) == 0
            && ($data['hash'] ?? []) === [];
    }

    /**
     * @param HistoryQuery $data
     * @return CacheResult<History>
     */
    private function loadHistory(array $data, bool $cacheable, ?CacheContext $context, ?Cancellation $cancellation = null): CacheResult
    {
        if (!$cacheable) {
            $context?->bypassResponseCache();
        }
        // Keep cached and bypassed loads separate, even when their API parameters match.
        $key = self::historyCacheKey($data, $cacheable);
        return $this->cache->get(
            $key,
            /** @return CacheResult<History> */
            function (Cancellation $cancellation) use ($data, $cacheable): CacheResult {
                /** @var History $history */
                $history = self::getArrayContents($this->get('getHistoryHtml', $data['peer'], ['data' => $data], cancellation: $cancellation), cancellation: $cancellation);
                $loadedAt = $this->cache->now();
                return new CacheResult($history, $loadedAt, 'history', $cacheable ? CacheStatus::MISS : CacheStatus::BYPASS);
            },
            cancellation: $cancellation,
        );
    }

    /** @param HistoryQuery $data */
    private static function historyCacheKey(array $data, bool $cacheable): string
    {
        ksort($data);
        return 'history:' . $data['peer'] . ':' . ($cacheable
            ? 'window'
            : 'bypass:' . hash('sha256', json_encode($data, JSON_THROW_ON_ERROR)));
    }

    /**
     * @param CacheResult<History> $result
     * @param History|null $history
     * @return History
     */
    private function historyResponse(CacheResult $result, ?CacheContext $context, ?array $history = null): array
    {
        $context?->observe($result);
        return $history ?? $result->value;
    }

    public function getCache(): TelegramCache
    {
        return $this->cache;
    }

    /**
     * @return Message
     */
    public function getMessage(string $peer, int $id, ?CacheContext $context = null, ?Cancellation $cancellation = null): array
    {
        $cancellation?->throwIfRequested();
        $peer = TelegramCache::normalizeChannel($peer);
        $key = TelegramCache::messageKey($peer, $id);
        /** @var CacheResult<Message>|null $result */
        $result = $this->cache->getCached($key, useStale: true);
        /** @var CacheResult<History>|null $history */
        $history = $this->cache->getCached(self::historyCacheKey(['peer' => $peer], true), useStale: true);
        if ($history && (!$result || $history->loadedAt > $result->loadedAt)) {
            foreach ($history->value['messages'] ?? [] as $message) {
                if (($message['id'] ?? null) == $id && in_array($message['_'] ?? '', ['message', 'messageService'], true)) {
                    /** @var Message $message */
                    $result = new CacheResult($message, $history->loadedAt, 'history', $history->status);
                    break;
                }
            }
        }
        $result ??= $this->cache->get(
            $key,
            /** @return CacheResult<Message> */
            function (Cancellation $cancellation) use ($peer, $id): CacheResult {
                $data = self::getArrayContents($this->get('getMessages', $peer, ['peer' => $peer, 'id' => [$id]], cancellation: $cancellation), cancellation: $cancellation);
                foreach ($data['messages'] ?? [] as $message) {
                    if (($message['id'] ?? null) == $id && in_array($message['_'] ?? '', ['message', 'messageService'], true)) {
                        /** @var Message $message */
                        return new CacheResult($message, $this->cache->now(), 'getMessages', CacheStatus::MISS);
                    }
                }
                $this->cache->forgetMessage($peer, $id);
                throw new UnexpectedValueException('Empty message', 400);
            },
            useStale: true,
            cancellation: $cancellation,
        );
        if ($context !== null) {
            $context->observe($result);
            $context->messageSource = $result->source;
        }
        return $result->value;
    }

    /**
     * @param MediaQuery $data
     * @param Headers $headers
     */
    public function getMedia(array $data, array $headers, bool $preview = false, ?CacheContext $context = null, ?Cancellation $cancellation = null): HttpResponse
    {
        $data = array_merge(
            [
                'peer' => '',
                'id' => [0],
            ],
            $data
        );
        $this->cache->checkCooldown((string) $data['peer']);
        $data['message'] = $this->getMessage((string) $data['peer'], (int) ((array) $data['id'])[0], $context, cancellation: $cancellation);
        return $this->get($preview ? 'getMediaPreview' : 'getMedia', (string) $data['peer'], ['data' => $data], $headers, 'media', cancellation: $cancellation);
    }

    /**
     * @param MediaQuery $data
     * @param Headers $headers
     */
    public function getMediaPreview(array $data, array $headers, ?CacheContext $context = null, ?Cancellation $cancellation = null): HttpResponse
    {
        return $this->getMedia($data, $headers, true, $context, cancellation: $cancellation);
    }

    /**
     * @param array<string, mixed> $messageMedia
     * @param Headers $headers
     */
    public function downloadToResponse(array $messageMedia, array $headers, string $peer, ?Cancellation $cancellation = null): HttpResponse
    {
        return $this->get('downloadToResponse', $peer, ['messageMedia' => $messageMedia], $headers, 'media', cancellation: $cancellation);
    }

    /**
     * @param array<string, mixed> $message
     * @return array{size?: int, mime?: string, name?: string, ext?: string, ...}
     */
    public function getMediaInfo(array $message, string $peer, ?Cancellation $cancellation = null): array
    {
        return self::getArrayContents($this->get('getDownloadInfo', $peer, ['message' => $message], cancellation: $cancellation), cancellation: $cancellation);
    }

    /**
     * @return array{type: string, ...}
     */
    public function getInfo(string $peer, ?string $requestPeer = null, ?CacheContext $context = null, ?Cancellation $cancellation = null): array
    {
        $cancellation?->throwIfRequested();
        $requestPeer ??= $peer;
        // Icons and calls outside cached routes keep their existing behaviour.
        if ($context === null) {
            return self::getArrayContents($this->get('getInfo', $requestPeer, $peer, cancellation: $cancellation), cancellation: $cancellation);
        }
        $peer = TelegramCache::normalizeChannel($peer);
        $result = $this->cache->get('info:' . $peer,
            fn (Cancellation $cancellation) => new CacheResult(self::getArrayContents($this->get('getInfo', $requestPeer, $peer, cancellation: $cancellation), cancellation: $cancellation),
                $this->cache->now(), 'api', CacheStatus::MISS), cancellation: $cancellation);
        $context?->observe($result);
        return $result->value;
    }

    /**
     * @return array{Chat?: array{title?: string, ...}, full?: array{about?: string, ...}, ...}
     */
    public function getFullInfo(string $peer, ?Cancellation $cancellation = null): array
    {
        return self::getArrayContents($this->get('getFullInfo', $peer, $peer, cancellation: $cancellation), cancellation: $cancellation);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getPropicInfo(string $peer, ?Cancellation $cancellation = null): ?array
    {
        $result = self::getContents($this->get('getPropicInfo', $peer, $peer, cancellation: $cancellation), cancellation: $cancellation);
        if ($result !== null && !is_array($result)) {
            throw new UnexpectedValueException('Invalid API response: expected avatar or null', HttpStatus::BAD_GATEWAY);
        }
        return $result;
    }

    /**
     * @param int|string|array<string, mixed> $chat
     */
    public function getId($chat, string $peer, ?Cancellation $cancellation = null): string
    {
        $result = self::getContents($this->get('getId', $peer, [$chat], cancellation: $cancellation), cancellation: $cancellation);
        if (!is_int($result) && !is_string($result)) {
            throw new UnexpectedValueException('Invalid API response: expected peer ID', HttpStatus::BAD_GATEWAY);
        }
        return (string) $result;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getSponsoredMessages(string $peer, ?CacheContext $context = null, ?Cancellation $cancellation = null): array
    {
        $cancellation?->throwIfRequested();
        if ($this->isPremium === null) {
            $self = self::getArrayContents($this->get('getSelf', $peer, cancellation: $cancellation), cancellation: $cancellation);
            $this->isPremium = $self['premium'] ?? null;
        }
        $messages = [];
        if (!$this->isPremium) {
            $messages = self::getArrayContents($this->get('getSponsoredMessages', $peer, $peer, cancellation: $cancellation), cancellation: $cancellation);
            /** @var list<\Amp\Future<void>> $futures */
            $futures = [];
            foreach ($messages as $message) {
                if (!empty($message['from_id'])) {
                    $futures[] = async(function() use(&$message, $peer, $context, $cancellation) {
                        $id = $this->getId($message['from_id'], $peer, cancellation: $cancellation);
                        $message['peer'] = $this->getInfo($id, $peer, $context, cancellation: $cancellation);
                    });
                }
            }
            awaitAll($futures, $cancellation);
            $cancellation?->throwIfRequested();
        }
        return $messages;
    }

    /**
     * @param array<string, mixed> $message
     * @return Response|ServerResponse
     */
    public function viewSponsoredMessage(string $peer, array $message, ?Cancellation $cancellation = null)
    {
        return $this->get('viewSponsoredMessage', $peer, ['peer' => $peer, 'message' => $message], cancellation: $cancellation);
    }

    /**
     *
     * @throws \Exception
     * @param array<array-key, mixed>|string $parameters
     * @param Headers $headers
     */
    private function get(
        string $method,
        string $peer,
        array|string $parameters = [],
        array $headers = [],
        string $responseType = 'json',
        int $retry = 0,
        ?Cancellation $cancellation = null,
    ): Response|ServerResponse {
        $cancellation?->throwIfRequested();
        $this->cache->checkCooldown($peer);
        unset(
            $headers['host'],
            $headers['remote_addr'],
            $headers['x-forwarded-for'],
            $headers['connection'],
            $headers['cache-control'],
            $headers['upgrade-insecure-requests'],
            $headers['accept-encoding'],
        );
        if ($retry) {
            if ($retry >= static::RETRY) {
                throw new UnexpectedValueException(static::MESSAGE_CLIENT_UNAVAILABLE, 500);
            }
            //Делаем попытку реконекта
            echo 'Client crashed and restarting. Resending request.' . PHP_EOL;
            Logger::getInstance()->warning('Client crashed and restarting. Resending request.');
            delay(self::RETRY_INTERVAL, cancellation: $cancellation);
        }

        $request = new Request(
            $this->apiUrl . "/api/$method",
            'POST',
            json_encode($parameters, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_IGNORE)
        );
        $request->setHeaders(array_merge(['Content-Type' => 'application/json'], $headers));
        $request->setTransferTimeout(1800.0);
        $request->setInactivityTimeout(15.0);
        $request->setBodySizeLimit(bodySizeLimit: 5 * (1024 ** 3)); // 5G
        $request->setTcpConnectTimeout(0.1);
        $request->setTlsHandshakeTimeout(0.1);
        $this->cache->checkCooldown($peer);
        try {
            $response = $this->client->request($request, $cancellation);
        } catch (CancelledException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->get($method, $peer, $parameters, $headers, $responseType, ++$retry, cancellation: $cancellation);
        }

        if (!in_array($response->getStatus(), [200, 206, 302], true)) {
            $errorMessage = '';
            $errorCode = 400;
            if (str_contains((string)$response->getHeader('Content-Type'), 'application/json')) {
                $data = json_decode($response->getBody()->buffer($cancellation), true);
                $errorMessage = $data['errors'][0]['message'] ?? $errorMessage;
                $errorCode = $data['errors'][0]['code'] ?? $errorCode;
            }


            if (!$errorMessage) {
                return $this->get($method, $peer, $parameters, $headers, $responseType, ++$retry, cancellation: $cancellation);
            }
            if ($errorMessage) {
                if ($errorMessage === 'Message has no preview' || $errorMessage === 'Empty preview') {
                    return new ServerResponse(HttpStatus::TEMPORARY_REDIRECT, ['location' => '/no-image.jpg']);
                } elseif (str_starts_with($errorMessage, 'FLOOD_WAIT')) {
                    preg_match('/^FLOOD_WAIT_(\d+)/', $errorMessage, $wait);
                    throw $this->cache->floodWait($peer, (int) ($wait[1] ?? 1));
                }
                throw new UnexpectedValueException($errorMessage, $errorCode);
            }
            throw new UnexpectedValueException(static::MESSAGE_CLIENT_UNAVAILABLE, $response->getStatus());
        }

        return $response;
    }

    private static function getContents(Response|ServerResponse $response, ?Cancellation $cancellation = null): mixed
    {
        if (!str_contains((string) $response->getHeader('Content-Type'), 'application/json')) {
            throw new UnexpectedValueException('Invalid API response: expected JSON', HttpStatus::BAD_GATEWAY);
        }
        $data = json_decode(\Amp\ByteStream\buffer($response->getBody(), $cancellation), true, 50, JSON_THROW_ON_ERROR);
        if (!is_array($data) || !array_key_exists('response', $data)) {
            throw new UnexpectedValueException('Invalid API response: missing response field', HttpStatus::BAD_GATEWAY);
        }
        if (($data['success'] ?? true) !== true || !empty($data['errors'])) {
            throw new UnexpectedValueException('Invalid API response: API reported an error', HttpStatus::BAD_GATEWAY);
        }
        return $data['response'];
    }

    /** @return array<array-key, mixed> */
    private static function getArrayContents(Response|ServerResponse $response, ?Cancellation $cancellation = null): array
    {
        $result = self::getContents($response, cancellation: $cancellation);
        if (!is_array($result)) {
            throw new UnexpectedValueException('Invalid API response: expected array', HttpStatus::BAD_GATEWAY);
        }
        return $result;
    }
}
