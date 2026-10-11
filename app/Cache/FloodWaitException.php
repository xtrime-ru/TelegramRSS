<?php

namespace TelegramRSS\Cache;

final class FloodWaitException extends \UnexpectedValueException
{
    public function __construct(public readonly int $retryAfter)
    {
        parent::__construct('FLOOD_WAIT_' . $retryAfter, 429);
    }
}
