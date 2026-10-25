<?php

namespace TelegramRSS\Cache;

use Symfony\Component\Cache\Adapter\ArrayAdapter;

/** @template T */
final class MemoryCache extends ArrayAdapter
{
    public function __construct(int $maxItems)
    {
        // ArrayAdapter treats zero as unlimited; the owner disables writes separately.
        parent::__construct(0, false, 0, max(1, $maxItems));
    }

    /** @return T|null */
    public function getValue(string $key): mixed
    {
        return $this->getItem($key)->get();
    }

    /** @param T $value */
    public function setValue(string $key, mixed $value): void
    {
        $this->save($this->getItem($key)->set($value));
    }

    /** @return array<array-key, T> */
    public function getValues(): array
    {
        return parent::getValues();
    }
}
