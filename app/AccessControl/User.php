<?php

namespace TelegramRSS\AccessControl;

use Symfony\Component\RateLimiter\Policy\SlidingWindowLimiter;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

class User
{
    public int $rpmLimit = 15;
    public int $errorsLimit = 0;
    public int $rateRemaining = 0;
    public int $rateRetryAfter = 0;
    private bool $rateLimited = false;
    private ?SlidingWindowLimiter $limiter = null;
    private int $lastRequestTs = 0;

    /** @var list<array{message: string, url: string, ts: int}> */
    public array $errors = [];

    public int $banLastDuration = 0;
    private int $banUntilTs = 0;


    private const RPM_TTL = '-1 minute';
    private const TTL = '-5 minutes';
    private const BAN_TTL = '-24 hours';

    private const BAN_DURATION_STEPS = [
        1 * 60,
        5 * 60,
        30 * 60,
        60 * 60,
        6 * 60 * 60,
        12 * 60 * 60,
        24 * 60 * 60,
    ];

    public function __construct(?int $rpmLimit = null, ?int $errorsLimit = null)
    {
        $this->configure($rpmLimit ?? 15, $errorsLimit ?? 0);
        if ($this->rpmLimit === 0) {
            $this->addError("Request from this IP forbidden", '');
        }
    }

    /** Apply startup settings to a new or restored user. */
    public function configure(int $rpmLimit, int $errorsLimit): self
    {
        if ($rpmLimit < -1) {
            throw new \InvalidArgumentException('RPM must be -1, 0 or positive');
        }
        if ($rpmLimit !== $this->rpmLimit) {
            $this->limiter = null;
            $this->rateLimited = false;
            $this->rateRemaining = $this->rateRetryAfter = 0;
        }
        $this->rpmLimit = $rpmLimit;
        $this->errorsLimit = $errorsLimit;
        return $this;
    }

    /**
     * @return array{rpmLimit: int, errorsLimit: int, lastRequestTs: int, errors: list<array{message: string, url: string, ts: int}>, banLastDuration: int, banUntilTs: int}
     */
    public function __serialize(): array
    {
        return [
            'rpmLimit' => $this->rpmLimit,
            'errorsLimit' => $this->errorsLimit,
            'lastRequestTs' => $this->lastRequestTs,
            'errors' => $this->errors,
            'banLastDuration' => $this->banLastDuration,
            'banUntilTs' => $this->banUntilTs,
        ];
    }

    /**
     * @param array{rpmLimit?: int, errorsLimit?: int, lastRequestTs?: int, errors?: list<array{message: string, url: string, ts: int}>, banLastDuration?: int, banUntilTs?: int} $data
     */
    public function __unserialize(array $data): void
    {
        $this->rpmLimit = $data['rpmLimit'] ?? 15;
        $this->errorsLimit = $data['errorsLimit'] ?? 0;
        $this->lastRequestTs = $data['lastRequestTs'] ?? 0;
        $this->errors = $data['errors'] ?? [];
        $this->banLastDuration = $data['banLastDuration'] ?? 0;
        $this->banUntilTs = $data['banUntilTs'] ?? 0;
    }

    public function isOld(?int $now = null): bool
    {
        if (\is_null($now)) {
            $now = time();
        }

        return $this->getLastAccessTs() < strtotime(static::TTL, $now)
            && $this->banUntilTs < strtotime(static::BAN_TTL)
        ;
    }

    public function addRequest(): void
    {
        if ($this->isBanned()) {
            return;
        }

        $this->lastRequestTs = time();
        $this->rateLimited = false;
        $this->rateRetryAfter = 0;
        if ($this->rpmLimit === -1) {
            return;
        }
        $result = $this->getLimiter()->consume();
        $this->rateLimited = !$result->isAccepted();
        $this->rateRemaining = $result->getRemainingTokens();
        if ($this->rateLimited) {
            $this->rateRetryAfter = max(1, (int) ceil($result->getRetryAfter()->getTimestamp() - microtime(true)));
        }
    }

    public function isRateLimited(): bool
    {
        return $this->rpmLimit === 0 || $this->rateLimited;
    }

    /** Read the current Symfony quota without consuming a request. */
    public function getRateLimit(): ?RateLimit
    {
        return $this->rpmLimit > 0 ? $this->getLimiter()->consume(0) : null;
    }

    private function getLimiter(): SlidingWindowLimiter
    {
        return $this->limiter ??= new SlidingWindowLimiter('requests-', $this->rpmLimit,
            new \DateInterval('PT1M'), new InMemoryStorage());
    }

    public function getLastAccessTs(): int
    {
        return $this->lastRequestTs;
    }

    public function isBanned(): bool
    {
        return $this->rpmLimit === 0 || $this->banUntilTs > time();
    }

    public function getBanDuration(): ?string
    {
        if ($this->rpmLimit !== 0) {
            $timeLeft = $this->banUntilTs - time();

            if ($timeLeft > 0) {
                return gmdate('H:i:s', $timeLeft);
            }
        }

        return null;
    }

    public function addBan(): void
    {
        if ($this->isBanned()) {
            return;
        }

        foreach (static::BAN_DURATION_STEPS as $duration) {
            if ($this->banLastDuration < $duration) {
                $this->banLastDuration = $duration;
                break;
            }
        }
        $this->banUntilTs = time() + $this->banLastDuration;

    }

    public function addError(string $reason, string $url): void
    {
        $this->errors[] = [
            'message' => $reason,
            'url' => $url,
            'ts' => time(),
        ];

        $this->trimByTtl($this->errors, static::RPM_TTL);

        if ($this->errorsLimit !== -1 && \count($this->errors) > $this->errorsLimit) {
            $this->addBan();
        }
    }

    /**
     * @template T of int|float|array<string, mixed>
     * @param list<T> $array
     * @return list<T>
     */
    private function trimByTtl(array &$array, string $ttl, ?string $tsKey = 'ts'): array
    {
        $ttlTs = strtotime($ttl, time());

        $oldCount = 0;
        foreach ($array as $key => $item) {
            $ts = is_numeric($item) ? $item : $item[$tsKey];

            if ($ts > $ttlTs) {
                break;
            }

            $oldCount++;
        }

        if ($oldCount > 0) {
            array_splice($array, 0, $oldCount);
        }

        return $array;
    }


}
