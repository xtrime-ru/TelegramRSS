<?php

namespace TelegramRSS\Cache;

enum CacheStatus: string
{
    case HIT = 'HIT';
    case MISS = 'MISS';
    case BYPASS = 'BYPASS';
    case STALE = 'STALE';
    case COALESCED = 'COALESCED';
}
