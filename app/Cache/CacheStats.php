<?php

namespace TelegramRSS\Cache;

final class CacheStats
{
    public function __construct(
        public readonly int $channels,
        public readonly int $loading,
        public readonly int $cooldowns,
        public readonly int $messages = 0,
        public readonly int $payloadBytes = 0,
    ) {
    }
}
