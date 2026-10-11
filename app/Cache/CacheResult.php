<?php

namespace TelegramRSS\Cache;

/** @template-covariant T */
final class CacheResult
{
    /**
     * @param T $value
     */
    public function __construct(
        public readonly mixed $value,
        public readonly float $loadedAt,
        public readonly string $source,
        public readonly CacheStatus $status,
    ) {
    }

    /**
     * @return self<T>
     */
    public function withStatus(CacheStatus $status): self
    {
        return new self($this->value, $this->loadedAt, $this->source, $status);
    }
}
