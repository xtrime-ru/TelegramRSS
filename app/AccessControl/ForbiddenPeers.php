<?php

namespace TelegramRSS\AccessControl;

use Amp\ByteStream\StreamException;
use Amp\File\FilesystemException;
use TelegramRSS\Config;
use TelegramRSS\Logger;
use TelegramRSS\Cache\TelegramCache;

use function Amp\ByteStream\splitLines;
use function Amp\File\isFile;
use function Amp\File\openFile;

final class ForbiddenPeers
{

    private static ?string $regex = null;

    /** @var array<array-key, string>|null */
    private static ?array $peers = null;

    private const FILE = ROOT_DIR . '/cache/forbidden-peers.csv';

    public static function add(string $peer, string $error): void {
        static $errorRegex = null;
        if ($errorRegex === null) {
            $errorRegex = Config::getInstance()->get('access.cache_peer_errors_regex');
        }
        if ($errorRegex && preg_match("/{$errorRegex}/", $error)) {
            $peer = TelegramCache::normalizeChannel($peer);
            self::check($peer);
            if ((self::$peers[$peer] ?? null) !== $error) {
                self::$peers[$peer] = $error;
                try {
                    $file = openFile(self::FILE, 'ab');
                    try {
                        $file->write(self::str_putcsv([$peer, $error]) . PHP_EOL);
                    } finally {
                        $file->close();
                    }
                } catch (FilesystemException | StreamException $exception) {
                    Logger::getInstance()->error('Failed to append forbidden peer', [
                        'peer' => $peer, 'exception' => $exception,
                    ]);
                }
            }
        }
    }

    /**
     * Return error if peer forbidden or previously failed with error
     *
     * @param string $peer
     *
     * @return string|null
     */
    public static function check(string $peer): ?string {
        $peer = TelegramCache::normalizeChannel($peer);

        if (self::$regex === null) {
            self::$regex = (string)Config::getInstance()->get('access.forbidden_peer_regex');
        }

        if (self::$peers === null) {
            $peers = [];
            if (isFile(self::FILE)) {
                $file = openFile(self::FILE, 'rb');
                try {
                    foreach (splitLines($file) as $line) {
                        $csv = str_getcsv(trim($line), escape: "");
                        if (count($csv) === 2) {
                            [$oldPeer, $error] = $csv;
                            if ($oldPeer && $error) {
                                $peers[TelegramCache::normalizeChannel($oldPeer)] = $error;
                            }
                        }
                    }
                } finally {
                    $file->close();
                }
            }
            self::$peers = $peers;
        }

        $regex = self::$regex;
        if ($regex && preg_match("/{$regex}/i", $peer)) {
            return "PEER NOT ALLOWED";
        }

        return self::$peers[$peer] ?? null;
    }

    /**
     * @param list<string> $input
     * @param string $delimiter
     * @param string $enclosure
     * @return string
     */
    static function str_putcsv($input, $delimiter = ',', $enclosure = '"') {
        $fp = fopen('php://memory', 'r+b');
        try {
            fputcsv($fp, $input, $delimiter, $enclosure, "");
            rewind($fp);
            return rtrim(stream_get_contents($fp), "\n");
        } finally {
            fclose($fp);
        }
    }
}
