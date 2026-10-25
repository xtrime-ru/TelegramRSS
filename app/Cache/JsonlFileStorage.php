<?php

namespace TelegramRSS\Cache;

use Amp\ByteStream\ReadableIterableStream;
use Amp\Pipeline\Pipeline;
use TelegramRSS\Logger;

use function Amp\ByteStream\splitLines;
use function Amp\File\deleteFile;
use function Amp\File\isFile;
use function Amp\File\move;
use function Amp\File\openFile;

trait JsonlFileStorage
{
    /** @return \Generator<int, string> */
    private function readCacheLines(string $path, bool $compressed = false): \Generator
    {
        $file = openFile($path, 'rb');
        try {
            if (!$compressed) {
                yield from splitLines($file);
                return;
            }
            $context = zstd_uncompress_init() ?: throw new \RuntimeException('Cannot initialize cache decompression');
            $chunks = Pipeline::fromIterable($file)->map(static function (string $chunk) use ($context): string {
                $data = @zstd_uncompress_add($context, $chunk);
                return $data !== false ? $data : throw new \RuntimeException('Cache decompression failed');
            });
            $stream = new ReadableIterableStream($chunks);
            try {
                yield from splitLines($stream);
            } finally {
                $stream->close();
            }
        } finally {
            $file->close();
        }
    }

    /** @param iterable<array<array-key, mixed>> $records */
    private function writeCacheFile(string $path, iterable $records, bool $compressed = false): bool
    {
        $temporary = $path . '.tmp';
        try {
            $file = openFile($temporary, 'wb');
            try {
                $context = $compressed ? zstd_compress_init(1) : null;
                if ($context === false) {
                    throw new \RuntimeException('Cannot initialize cache compression');
                }
                foreach ($records as $record) {
                    $line = json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
                        | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION) . "\n";
                    $data = $context ? zstd_compress_add($context, $line) : $line;
                    if ($data === false) {
                        throw new \RuntimeException('Cache compression failed');
                    }
                    if ($data !== '') {
                        $file->write($data);
                    }
                }
                if ($context) {
                    $data = zstd_compress_add($context, '', true);
                    if ($data === false) {
                        throw new \RuntimeException('Cannot finish cache compression');
                    }
                    $file->write($data);
                }
            } finally {
                $file->close();
            }
            move($temporary, $path);
            return true;
        } catch (\Throwable $exception) {
            Logger::getInstance()->error('Failed to save cache file', ['path' => $path, 'exception' => $exception]);
            return false;
        } finally {
            $this->removeCacheFile($temporary);
        }
    }

    private function removeCacheFile(string $path): void
    {
        try {
            if (isFile($path)) {
                deleteFile($path);
            }
        } catch (\Throwable $exception) {
            Logger::getInstance()->error('Failed to remove cache file', ['path' => $path, 'exception' => $exception]);
        }
    }
}
