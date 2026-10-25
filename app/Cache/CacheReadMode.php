<?php

namespace TelegramRSS\Cache;

enum CacheReadMode
{
    case Fresh;
    case Stale;
    case OnFlood;
}
