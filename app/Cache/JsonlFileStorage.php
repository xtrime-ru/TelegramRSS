<?php

namespace TelegramRSS\Cache;

use TelegramRSS\Logger;

use function Amp\ByteStream\splitLines;
use function Amp\File\deleteFile;
use function Amp\File\isFile;
use function Amp\File\move;
use function Amp\File\openFile;

trait JsonlFileStorage
{
    /** @return \Generator<int, string> */
    private function readCacheLines(string $path): \Generator
    {
        $file = openFile($path, 'rb');
        try {
            yield from splitLines($file);
        } finally {
            $file->close();
        }
    }

    /** @param iterable<array<array-key, mixed>> $records */
    private function writeCacheFile(string $path, iterable $records): void
    {
        $temporary = $path . '.tmp';
        try {
            $file = openFile($temporary, 'wb');
            try {
                foreach ($records as $record) {
                    $file->write(json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
                        | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION) . "\n");
                }
            } finally {
                $file->close();
            }
            move($temporary, $path);
        } catch (\Throwable $exception) {
            Logger::getInstance()->error('Failed to save cache file', ['path' => $path, 'exception' => $exception]);
        } finally {
            try {
                if (isFile($temporary)) {
                    deleteFile($temporary);
                }
            } catch (\Throwable $exception) {
                Logger::getInstance()->error('Failed to remove temporary cache file', ['path' => $temporary, 'exception' => $exception]);
            }
        }
    }
}
