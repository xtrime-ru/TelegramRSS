<?php

namespace TelegramRSS\Cache;

/**
 * JSON payload, optionally compressed; freshness is checked without decoding.
 * @template-covariant T
 */
final class CacheEntry
{
    public function __construct(
        public readonly string $data,
        public readonly float $loadedAt,
        public readonly string $source,
    ) {
    }

    public static function compressionAvailable(): bool
    {
        return function_exists('zstd_compress_init') && function_exists('zstd_uncompress_init');
    }

    /**
     * @template V
     * @param V $value
     * @return self<V>
     */
    public static function pack(mixed $value, float $loadedAt, string $source): self
    {
        $json = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
            | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION);
        $data = self::compressionAvailable() ? zstd_compress($json, 1) : $json;
        if ($data === false) {
            throw new \RuntimeException('Cache compression failed');
        }
        return new self($data, $loadedAt, $source);
    }

    /**
     * @template V
     * @param self<V>|null $previous
     * @param V $value
     * @return self<V>
     */
    public static function newer(?self $previous, mixed $value, float $loadedAt, string $source): self
    {
        return $previous && $previous->loadedAt > $loadedAt ? $previous : self::pack($value, $loadedAt, $source);
    }

    /** @return T */
    public function unpack(): mixed
    {
        $json = self::compressionAvailable() ? zstd_uncompress($this->data) : $this->data;
        if ($json === false) {
            throw new \RuntimeException('Cache decompression failed');
        }
        return json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    }
}
