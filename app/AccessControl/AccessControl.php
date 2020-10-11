<?php

namespace TelegramRSS\AccessControl;

use Swoole\Timer;
use TelegramRSS\Config;

class AccessControl
{
    /** @var User[] */
    private array $users = [];
    /** @var User[] */
    private array $mediaUsers = [];

    /** @var int Interval to remove old clients: 60 seconds */
    private const CLEANUP_INTERVAL_MS = 60*1000;
    private int $rpmLimit;
    private int $errorsLimit;

    private int $mediaRpmLimit;
    private int $mediaErrorsLimit;

    /** @var int[]  */
    private array $clientsSettings;

    public function __construct()
    {
        $this->rpmLimit = (int) Config::getInstance()->get('access.rpm');
        $this->errorsLimit = (int) Config::getInstance()->get('access.errors_limit');

        $this->mediaRpmLimit = (int) Config::getInstance()->get('access.media_rpm');
        $this->mediaErrorsLimit = (int) Config::getInstance()->get('access.media_errors_limit');

        $this->clientsSettings = (array) Config::getInstance()->get('access.clients_settings');

        Timer::tick(static::CLEANUP_INTERVAL_MS, function () {
            $this->removeOldUsers();
        });
    }

    private function removeOldUsers(): void
    {
        $now = time();
        foreach ($this->users as $ip => $user) {
            if ($user->isOld($now)) {
                unset($this->users[$ip]);
            }
        }
        foreach ($this->mediaUsers as $ip => $user) {
            if ($user->isOld($now)) {
                unset($this->mediaUsers[$ip]);
            }
        }
    }

    public function getOrCreateUser(string $ip, string $type = 'default'): User
    {
        if ($type === 'media') {
            if (!isset($this->mediaUsers[$ip])) {
                $this->mediaUsers[$ip] = new User(
                    $this->clientsSettings[$ip]['media_rpm'] ?? $this->clientsSettings[$ip]['rpm'] ?? $this->mediaRpmLimit,
                    $this->clientsSettings[$ip]['media_errors_limit'] ?? $this->clientsSettings[$ip]['errors_limit'] ?? $this->mediaErrorsLimit
                );
            }

            return $this->mediaUsers[$ip];
        } else {
            if (!isset($this->users[$ip])) {
                $this->users[$ip] = new User(
                    $this->clientsSettings[$ip]['rpm'] ?? $this->rpmLimit,
                    $this->clientsSettings[$ip]['errors_limit'] ?? $this->errorsLimit
                );
            }

            return $this->users[$ip];
        }

    }

}