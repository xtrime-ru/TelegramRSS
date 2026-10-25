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
use TelegramRSS\Cache\CacheEntry;
use TelegramRSS\Cache\PeerCache;
use TelegramRSS\Cache\CacheReadMode;
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
        $cacheable = !$context?->bypassHistory && $this->usesHistoryWindow($data);
        $store = $cacheable && $data['add_offset'] === 0;
        $loader = function (Cancellation $shared) use ($data, $store): CacheResult {
            $history = self::getArrayContents($this->get('getHistoryHtml', $data['peer'], ['data' => $data], cancellation: $shared), cancellation: $shared);
            $shared->throwIfRequested();
            $loadedAt = $this->cache->now();
            if ($store) {
                $peer = $this->cache->peer($data['peer']);
                $peer->updateHistory($data['peer'], $history, $loadedAt, $data['limit']);
                $this->cache->commit($data['peer'], $peer);
            }
            return new CacheResult($history, $loadedAt, 'history', $store ? CacheStatus::MISS : CacheStatus::BYPASS);
        };
        $result = $this->cache->resolve(
            fn (bool $stale) => $cacheable ? $this->cache->history($data['peer'], $data['limit'], $data['add_offset'], $stale) : null,
            fn (Cancellation $shared) => $store
                ? $this->cache->fetchHistory($data['peer'], $data['limit'], $loader, $shared)
                : $this->cache->fetch('history:bypass:' . hash('sha256', json_encode($data, JSON_THROW_ON_ERROR)), $loader, $shared),
            $cacheable ? ($context?->readMode ?? CacheReadMode::Fresh) : CacheReadMode::Fresh,
            $cancellation,
        );
        $context?->observe($result);
        return $result->value;
    }

    /** @param HistoryQuery $data */
    private function usesHistoryWindow(array $data): bool
    {
        $limit = $data['limit'] ?? 10;
        $offset = $data['add_offset'] ?? 0;
        $peer = TelegramCache::normalizeChannel((string) ($data['peer'] ?? ''));
        return $this->cache->enabled && $limit > 0 && $limit <= 100 && $offset >= 0
            && ($offset === 0 || $this->cache->hasHistoryRange($peer, $offset, $limit))
            && ($data['offset_id'] ?? 0) == 0 && ($data['offset_date'] ?? 0) == 0
            && ($data['max_id'] ?? 0) == 0 && ($data['min_id'] ?? 0) == 0
            && ($data['hash'] ?? []) === [];
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
        $result = $this->cache->resolve(
            fn (bool $stale) => $this->cache->read($this->cache->peer($peer)->messages[$id] ?? null, $stale),
            fn (Cancellation $shared) => $this->cache->fetch('message:' . $peer . ':' . $id,
                function (Cancellation $shared) use ($peer, $id): CacheResult {
                    $data = self::getArrayContents($this->get('getMessages', $peer, ['peer' => $peer, 'id' => [$id]], cancellation: $shared), cancellation: $shared);
                    $message = self::findMessage($data['messages'] ?? [], $id)
                        ?? throw new UnexpectedValueException('Empty message', 400);
                    $shared->throwIfRequested();
                    $loadedAt = $this->cache->now();
                    if ($this->cache->enabled) {
                        $state = $this->cache->peer($peer);
                        $state->messages[$id] = CacheEntry::newer($state->messages[$id] ?? null, $message, $loadedAt, 'getMessages');
                        $this->cache->commit($peer, $state);
                    }
                    return new CacheResult($message, $loadedAt, 'getMessages', CacheStatus::MISS);
                }, $shared),
            CacheReadMode::Stale,
            $cancellation,
        );
        if ($context !== null) {
            $context->observe($result);
            $context->messageSource = $result->source;
        }
        return $result->value;
    }

    /**
     * @param list<array<string, mixed>> $messages
     * @return Message|null
     */
    private static function findMessage(array $messages, int $id): ?array
    {
        foreach ($messages as $message) {
            if (($message['id'] ?? null) == $id && in_array($message['_'] ?? '', ['message', 'messageService'], true)) {
                /** @var Message $message */
                return $message;
            }
        }
        return null;
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
    public function getMediaInfo(array $message, string $peer, ?Cancellation $cancellation = null, ?CacheContext $context = null): array
    {
        $peer = TelegramCache::normalizeChannel($peer);
        $hash = hash('sha256', json_encode($message, JSON_THROW_ON_ERROR));
        return $this->cachedData($peer, 'download:' . $peer . ':' . $hash,
            static fn (PeerCache $state) => $state->downloads[$hash] ?? null,
            static fn (PeerCache $state, CacheEntry $entry) => $state->downloads[$hash] = $entry,
            fn (Cancellation $shared) => self::getArrayContents($this->get('getDownloadInfo', $peer, ['message' => $message], cancellation: $shared), cancellation: $shared),
            $context, $cancellation);
    }

    /**
     * @return array{type: string, ...}
     */
    public function getInfo(string $peer, ?string $requestPeer = null, ?CacheContext $context = null, ?Cancellation $cancellation = null): array
    {
        $cancellation?->throwIfRequested();
        $requestPeer = TelegramCache::normalizeChannel($requestPeer ?? $peer);
        $peer = TelegramCache::normalizeChannel($peer);
        $main = $peer === $requestPeer;
        return $this->cachedData($requestPeer, 'info:' . $requestPeer . ':' . $peer,
            static fn (PeerCache $state) => $main ? $state->info : $state->peerInfo($peer),
            static function (PeerCache $state, CacheEntry $entry) use ($main, $peer): void {
                if ($main || (string) $state->infoId === $peer) {
                    $state->info = $entry;
                    $state->infoId = $entry->unpack()['bot_api_id'] ?? $state->infoId;
                } else {
                    $state->relatedInfo[$peer] = $entry;
                }
            },
            fn (Cancellation $shared) => self::getArrayContents($this->get('getInfo', $requestPeer, $peer, cancellation: $shared), cancellation: $shared),
            $context, $cancellation);
    }

    /**
     * @return array{Chat?: array{title?: string, ...}, full?: array{about?: string, ...}, ...}
     */
    public function getFullInfo(string $peer, ?Cancellation $cancellation = null, ?CacheContext $context = null): array
    {
        $peer = TelegramCache::normalizeChannel($peer);
        return $this->cachedData($peer, 'fullInfo:' . $peer,
            static fn (PeerCache $state) => $state->fullInfo,
            static fn (PeerCache $state, CacheEntry $entry) => $state->fullInfo = $entry,
            fn (Cancellation $shared) => self::getArrayContents($this->get('getFullInfo', $peer, $peer, cancellation: $shared), cancellation: $shared),
            $context, $cancellation);
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
        $peer = TelegramCache::normalizeChannel($peer);
        return $this->cachedData($peer, 'sponsored:' . $peer,
            static fn (PeerCache $state) => $state->sponsored,
            static fn (PeerCache $state, CacheEntry $entry) => $state->sponsored = $entry,
            fn (Cancellation $shared) => $this->loadSponsoredMessages($peer, $context, $shared), $context, $cancellation);
    }

    /**
     * @template T of array
     * @param \Closure(PeerCache): ?CacheEntry<T> $read
     * @param \Closure(PeerCache, CacheEntry<T>): mixed $write
     * @param \Closure(Cancellation): T $loader
     * @return T
     */
    private function cachedData(string $peer, string $key, \Closure $read, \Closure $write, \Closure $loader, ?CacheContext $context, ?Cancellation $cancellation): array
    {
        $result = $this->cache->resolve(
            fn (bool $stale) => $this->cache->read($read($this->cache->peer($peer)), $stale),
            fn (Cancellation $shared) => $this->cache->fetch($key,
                function (Cancellation $shared) use ($peer, $read, $write, $loader): CacheResult {
                    $value = $loader($shared);
                    $shared->throwIfRequested();
                    $loadedAt = $this->cache->now();
                    if ($this->cache->enabled) {
                        $state = $this->cache->peer($peer);
                        $write($state, CacheEntry::newer($read($state), $value, $loadedAt, 'api'));
                        $this->cache->commit($peer, $state);
                    }
                    return new CacheResult($value, $loadedAt, 'api', CacheStatus::MISS);
                }, $shared),
            $context?->readMode ?? CacheReadMode::Fresh,
            $cancellation,
        );
        $context?->observe($result);
        return $result->value;
    }

    /** @return list<array<string, mixed>> */
    private function loadSponsoredMessages(string $peer, ?CacheContext $context, ?Cancellation $cancellation): array
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
