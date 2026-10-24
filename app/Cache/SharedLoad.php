<?php

namespace TelegramRSS\Cache;

use Amp\Cancellation;
use Amp\DeferredCancellation;
use Amp\Future;

use function Amp\async;

/** A shared load lives until completion or until its last reader leaves. */
final class SharedLoad
{
    private readonly Future $future;
    private readonly DeferredCancellation $cancellation;
    private int $readers = 0;

    public function __construct(\Closure $loader)
    {
        $this->cancellation = new DeferredCancellation;
        $this->future = async($loader, $this->cancellation->getCancellation());
    }

    public function await(?Cancellation $cancellation): CacheResult
    {
        ++$this->readers;
        try {
            return $this->future->await($cancellation);
        } finally {
            if (--$this->readers === 0 && !$this->future->isComplete()) {
                $this->cancellation->cancel();
            }
        }
    }

    public function isFinished(): bool
    {
        return $this->future->isComplete() || $this->cancellation->isCancelled();
    }
}
