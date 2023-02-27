<?php

namespace TelegramRSS\AccessControl;

class User
{
    public int $rpmLimit = 15;
    public int $errorsLimit = 0;
    public int $rpm = 0;

    /** @var int[] array of timestamps */
    private array $requests = [];

    public array $errors = [];

    private bool $permanentBan = false;
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
        $this->rpmLimit = $rpmLimit ?? $this->rpmLimit;
        $this->errorsLimit = $errorsLimit ?? $this->errorsLimit;

        if ($this->rpmLimit === 0) {
            $this->permanentBan = true;
            $this->addError("Request from this IP forbidden", '');
        }
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

    public function addRequest(string $url): void
    {
        if ($this->isBanned()) {
            return;
        }

        $this->requests[] = time();
        $this->rpm = $this->getRPM();

        if ($this->rpmLimit === -1) {
            return;
        }

        if ($this->rpm > $this->rpmLimit) {
            $this->addError("Too many requests", $url);
        }
    }

    public function getLastAccessTs(): int
    {
        return end($this->requests);
    }

    public function isBanned(): bool
    {
        return $this->permanentBan || $this->banUntilTs > time();
    }

    public function getBanDuration(): ?string
    {
        if (!$this->permanentBan) {
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

    private function getRPM(): int
    {
        $this->trimByTtl($this->requests, static::RPM_TTL);
        return \count($this->requests);
    }

    private function trimByTtl(array &$array, string $ttl, ?string $tsKey = 'ts'): array
    {
        $ttlTs = strtotime($ttl);

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