<?php

namespace TelegramRSS\AccessControl;

use TelegramRSS\Client;
use TelegramRSS\Config;

final class ForbiddenPeers
{

    private static ?string $regex = null;

    /** @var array<string, string> */
    private static ?array $peers = null;

    private const FILE = ROOT_DIR . '/cache/forbidden-peers.csv';
    /** @var resource|null */
    private static $filePointer = null;

    public static function add(string $peer, string $error): void {
        switch ($error) {
            case 'This peer is not present in the internal peer database':
            case 'CHANNEL_PRIVATE':
            case 'USERNAME_INVALID':
            case 'BOTS NOT ALLOWED':
            case 'This is not a public channel':
                $peer = mb_strtolower($peer);

                self::$peers[$peer] = $error;
                fputcsv(self::getFilePointer(), [$peer, $error]);
                break;
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

        if (self::$peers === null) {
            if (file_exists(self::FILE)) {
                $file = self::getFilePointer();
                while(!feof($file)){
                    [$oldPeer, $error] = fgetcsv($file);
                    if ($oldPeer && $error) {
                        self::$peers[$oldPeer] = $error;
                    }
                }
            }
        }

        $regex = self::$regex;
        if ($regex && preg_match("/{$regex}/i", $peer)) {
            return "PEER NOT ALLOWED";
        }

        return self::$peers[$peer] ?? null;
    }

    /**
     * @return false|resource|null
     */
    private static function getFilePointer() {
        if (self::$filePointer === null) {
            self::$filePointer = fopen(self::FILE, 'cb+');
        }

        return self::$filePointer;
    }
}