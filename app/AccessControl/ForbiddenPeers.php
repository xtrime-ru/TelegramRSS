<?php

namespace TelegramRSS\AccessControl;

use TelegramRSS\Config;

final class ForbiddenPeers
{
    private static ?string $regex = null;

    private static $errorTypes = [
        'USERNAME_INVALID',
        'CHANNEL_PRIVATE',
        'This peer is not present in the internal peer database',
    ];

    /** @var array<string, string> */
    private static array $peers = [];

    public static function add(string $peer, string $error): void {
        $peer = mb_strtolower($peer);

        foreach (self::$errorTypes as $errorType) {
            if ($errorType === $error) {
                self::$peers[$peer] = $error;
                break;
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
        $peer = mb_strtolower($peer);

        if (self::$regex === null) {
            self::$regex = (string)Config::getInstance()->get('access.forbidden_peer_regex');
        }

        $regex = self::$regex;
        if ($regex && preg_match("/{$regex}/i", $peer)) {
            return "PEER NOT ALLOWED";
        }

        return self::$peers[$peer] ?? null;
    }
}