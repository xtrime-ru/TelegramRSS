<?php

namespace TelegramRSS\Controller;

final class FeedParameters
{
    public function __construct(
        public readonly int $page,
        public readonly int $limit,
        public readonly int $id,
    ) {
    }

    /** @return array{limit: int, add_offset: int, offset_id: int} */
    public function toHistoryQuery(): array
    {
        return [
            'limit' => $this->limit,
            'add_offset' => ($this->page - 1) * $this->limit,
            'offset_id' => $this->id ? $this->id + 1 : 0,
        ];
    }
}
