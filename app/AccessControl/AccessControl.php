<?php

namespace TelegramRSS\AccessControl;

use Swoole\Timer;
use TelegramRSS\Config;

class AccessControl
{
    /** @var User[] */
    private array $users = [];

    /** @var int Interval to remove old clients: 60 seconds */
    private const CLEANUP_INTERVAL_MS = 60*1000;
    private int $rpmLimit;
    private int $errorsLimit;
    /** @var int[]  */
    private array $clientsSettings;

    public function __construct()
    {
        $this->rpmLimit = (int) Config::getInstance()->get('access.default_rpm');
        $this->errorsLimit = (int) Config::getInstance()->get('access.default_errors_limit');
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
                $this->removeUser($ip);
            }
        }
    }

    private function removeUser(string $ip): void
    {
        unset($this->users[$ip]);
    }

    public function getOrCreateUser($ip)
    {
        if (!isset($this->users[$ip])) {
            $user = $this->users[$ip] = new User(
                $this->clientsSettings[$ip]['rpm'] ?? $this->rpmLimit,
                $this->clientsSettings[$ip]['errorsLimit'] ?? $this->errorsLimit
            );
        }

        return $this->users[$ip];
    }

}