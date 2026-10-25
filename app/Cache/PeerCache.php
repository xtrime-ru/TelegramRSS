<?php

namespace TelegramRSS\Cache;

/** One LRU unit for a channel, group or private dialog. */
final class PeerCache
{
    /** @var CacheEntry<array<string, mixed>>|null */
    public ?CacheEntry $history = null;
    /** @var CacheEntry<array<string, mixed>>|null */
    public ?CacheEntry $info = null;
    /** @var CacheEntry<array<string, mixed>>|null */
    public ?CacheEntry $fullInfo = null;
    /** @var CacheEntry<list<array<string, mixed>>>|null */
    public ?CacheEntry $sponsored = null;
    /** @var array<int, CacheEntry<array<string, mixed>>> */
    public array $messages = [];
    /** @var array<array-key, CacheEntry<array<string, mixed>>> */
    public array $relatedInfo = [];
    /** @var array<string, CacheEntry<array<string, mixed>>> */
    public array $downloads = [];
    /** @var list<int|null> Null marks an exceptional item kept in the history envelope. */
    public array $historyIds = [];
    /** @var list<int|null> */
    public array $historyChatIds = [];
    /** @var list<int|null> */
    public array $historyUserIds = [];
    public ?int $infoId = null;
    public int $historyLimit = 0;
    public bool $historyComplete = false;

    /** @param array<string, mixed> $history */
    public function updateHistory(string $peer, array $history, float $loadedAt, int $limit): void
    {
        if (($this->history?->loadedAt ?? -INF) > $loadedAt) {
            return;
        }
        $ids = $extra = [];
        foreach ($history['messages'] ?? [] as $position => $message) {
            if (isset($message['id']) && in_array($message['_'] ?? '', ['message', 'messageService'], true)) {
                $id = (int) $message['id'];
                $this->messages[$id] = CacheEntry::newer($this->messages[$id] ?? null, $message, $loadedAt, 'history');
                $ids[] = $id;
            } else {
                $ids[] = null;
                $extra[$position] = $message;
            }
        }
        $overlap = min(count($ids), count($this->historyIds));
        $conflict = array_slice($ids, 0, $overlap) !== array_slice($this->historyIds, 0, $overlap)
            || (isset($history['count']) && $history['count'] < count($this->historyIds));
        $retainTail = !$conflict && count($ids) < count($this->historyIds)
            && (!isset($history['count']) || $history['count'] > count($ids));
        $old = $retainTail ? $this->history?->unpack() : null;
        if ($retainTail) {
            $extra += array_filter($old['messages'] ?? [], static fn (int $position): bool => $position >= count($ids), ARRAY_FILTER_USE_KEY);
        } else {
            $this->historyIds = $ids;
        }
        $this->historyLimit = $conflict ? $limit : max($this->historyLimit, $limit);
        $this->historyComplete = isset($history['count']) && $history['count'] <= count($this->historyIds);
        $this->updatePeerInfo($peer, $history, $loadedAt);
        foreach (['chats' => 'historyChatIds', 'users' => 'historyUserIds'] as $field => $property) {
            $refs = $unknown = [];
            foreach ($history[$field] ?? [] as $position => $entity) {
                if (isset($entity['id'])) {
                    $refs[] = self::entityInfo($entity, $field)['bot_api_id'];
                } else {
                    $refs[] = null;
                    $unknown[$position] = $entity;
                }
            }
            if ($retainTail) {
                if (!array_key_exists($field, $history) && array_key_exists($field, $old ?? [])) {
                    $refs = $this->{$property};
                    $unknown = $old[$field];
                    $history[$field] = [];
                } else {
                    foreach ($this->{$property} as $id) {
                        if ($id !== null && !in_array($id, $refs, true)) {
                            $refs[] = $id;
                        }
                    }
                }
            }
            $this->{$property} = $refs;
            if (array_key_exists($field, $history)) {
                $history[$field] = $unknown;
            }
        }
        $history['messages'] = $extra;
        $this->history = CacheEntry::pack($history, $loadedAt, 'history');
    }

    /** @return CacheEntry<array<string, mixed>>|null */
    public function peerInfo(string $peer): ?CacheEntry
    {
        return (string) $this->infoId === $peer ? $this->info : ($this->relatedInfo[$peer] ?? null);
    }

    /** @param array<string, mixed> $history */
    private function updatePeerInfo(string $peer, array $history, float $loadedAt): void
    {
        $existing = $this->info?->unpack() ?? [];
        $targetId = is_numeric($peer) ? (int) $peer : ($existing['bot_api_id'] ?? null);
        $candidates = [];
        foreach (['chats', 'users'] as $field) {
            foreach ($history[$field] ?? [] as $entity) {
                if (!isset($entity['id'])) {
                    continue;
                }
                $info = self::entityInfo($entity, $field);
                $candidates[$info['bot_api_id']] = $info;
                $names = array_column(array_filter($entity['usernames'] ?? [], static fn (array $name): bool => $name['active'] ?? true), 'username');
                $names[] = $entity['username'] ?? '';
                if ($targetId === null && (in_array($peer, array_map(TelegramCache::normalizeChannel(...), $names), true)
                    || ($peer === 'me' && !empty($entity['self'])))) {
                    $targetId = $info['bot_api_id'];
                }
            }
        }
        if ($targetId === null) {
            $target = $history['messages'][0]['peer_id'] ?? null;
            $targetId = is_array($target) ? match ($target['_'] ?? '') {
                'peerChannel' => -1000000000000 - $target['channel_id'],
                'peerChat' => -$target['chat_id'],
                'peerUser' => $target['user_id'],
                default => null,
            } : $target;
        }
        foreach ($candidates as $id => $info) {
            if ($id === $targetId) {
                $this->infoId = $id;
                $this->info = CacheEntry::newer($this->info, $info + $existing, $loadedAt, 'history');
                unset($this->relatedInfo[$id]);
            } else {
                $this->relatedInfo[$id] = CacheEntry::newer($this->relatedInfo[$id] ?? null, $info, $loadedAt, 'history');
            }
        }
    }

    /** @param array<string, mixed> $entity
     * @return array{type: string, bot_api_id: int, ...}
     */
    private static function entityInfo(array $entity, string $field): array
    {
        $user = $field === 'users';
        $channel = str_starts_with($entity['_'] ?? '', 'channel') || str_starts_with($entity['_'] ?? '', 'community');
        $id = (int) $entity['id'];
        if (!$user && $id > 0) {
            $id = -$id - ($channel ? 1000000000000 : 0);
        }
        $type = $user ? (!empty($entity['bot']) ? 'bot' : 'user')
            : ($channel ? (!empty($entity['megagroup']) ? 'supergroup' : 'channel') : 'chat');
        return [$user ? 'User' : 'Chat' => $entity, 'type' => $type, 'bot_api_id' => $id];
    }

    /** @return \Generator<string, CacheEntry<mixed>> Only the disk boundary uses string entry names. */
    public function entries(): \Generator
    {
        foreach (['history' => $this->history, 'info' => $this->info, 'fullInfo' => $this->fullInfo, 'sponsored' => $this->sponsored] as $key => $entry) {
            if ($entry !== null) {
                yield $key => $entry;
            }
        }
        foreach ($this->messages as $id => $entry) {
            yield 'message:' . $id => $entry;
        }
        foreach ($this->relatedInfo as $id => $entry) {
            yield 'info:' . $id => $entry;
        }
        foreach ($this->downloads as $id => $entry) {
            yield 'download:' . $id => $entry;
        }
    }

    /** @param CacheEntry<mixed> $entry */
    public function restore(string $key, CacheEntry $entry): void
    {
        [$type, $id] = array_pad(explode(':', $key, 2), 2, null);
        match ($type) {
            'history' => $this->history = $entry,
            'info' => $id === null ? $this->info = $entry : $this->relatedInfo[$id] = $entry,
            'fullInfo' => $this->fullInfo = $entry,
            'sponsored' => $this->sponsored = $entry,
            'message' => $this->messages[(int) $id] = $entry,
            'download' => $this->downloads[$id] = $entry,
            default => throw new \UnexpectedValueException('Unknown snapshot entry: ' . $key),
        };
    }

    public function prune(float $oldest): void
    {
        $keep = static fn (CacheEntry $entry): bool => $entry->loadedAt >= $oldest;
        $this->history = $this->history && $keep($this->history) ? $this->history : null;
        $refs = $this->history ? array_fill_keys(array_filter(array_merge($this->historyChatIds, $this->historyUserIds), static fn ($id): bool => $id !== null), true) : [];
        $this->info = $this->info && ($keep($this->info) || isset($refs[$this->infoId ?? ''])) ? $this->info : null;
        $this->fullInfo = $this->fullInfo && $keep($this->fullInfo) ? $this->fullInfo : null;
        $this->sponsored = $this->sponsored && $keep($this->sponsored) ? $this->sponsored : null;
        $this->messages = array_filter($this->messages, $keep);
        $this->relatedInfo = array_filter($this->relatedInfo, static fn (CacheEntry $entry, $id): bool => $keep($entry) || isset($refs[$id]), ARRAY_FILTER_USE_BOTH);
        $this->downloads = array_filter($this->downloads, $keep);
    }
}
