<?php

namespace TelegramRSS\AccessControl;

use Revolt\EventLoop;
use TelegramRSS\Config;

use function Amp\ByteStream\splitLines;
use function Amp\File\isFile;
use function Amp\File\openFile;

class AccessControl
{
    /** @var User[] */
    private array $users = [];
    /** @var User[] */
    private array $mediaUsers = [];

    private const FILES = [
        ROOT_DIR . '/cache/users.cache' => 'users',
        ROOT_DIR . '/cache/media-users.cache' => 'mediaUsers',
    ];

    /** @var float Interval to remove old clients: 60 seconds */
    private const CLEANUP_INTERVAL_MS = 60.0;
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

        $this->loadUsers();

        EventLoop::repeat(static::CLEANUP_INTERVAL_MS, function () {
            $this->removeOldUsers();
            $this->saveUsers();
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

    private function saveUsers(): void {
        foreach (self::FILES as $path => $object) {
            $descriptor = openFile($path, 'wb');
            foreach ($this->{$object} as $key => $value) {
                $descriptor->write(serialize([$key=>$value]) . PHP_EOL);
            }
        }
    }

    private function loadUsers(): void {
        foreach (self::FILES as $path => $object) {
            if(isFile($path)) {
                $file = openFile($path, 'rb');
                while(!$file->eof()) {
                    foreach (splitLines($file) as $line) {
                        $line = trim($line);
                        $item = unserialize($line, ['allowed_classes'=>[User::class]]);
                        if (is_array($item)) {
                            foreach ($item as $ip => $user) {
                                $this->{$object}[$ip] = $user;
                            }
                        }

                    }
                }
            } else {
                $this->{$object} = [];
            }
        }
    }

    public function getOrCreateUser(string $ip, string $type = 'default'): User
    {
        if ($type === 'media') {
            if (!isset($this->mediaUsers[$ip])) {
                $this->mediaUsers[$ip] = new User();
            }

            $this->mediaUsers[$ip]->rpmLimit = $this->clientsSettings[$ip]['media_rpm'] ?? $this->clientsSettings[$ip]['rpm'] ?? $this->mediaRpmLimit;
            $this->mediaUsers[$ip]->errorsLimit = $this->clientsSettings[$ip]['media_errors_limit'] ?? $this->clientsSettings[$ip]['errors_limit'] ?? $this->mediaErrorsLimit;

            return $this->mediaUsers[$ip];
        } else {
            if (!isset($this->users[$ip])) {
                $this->users[$ip] = new User();
            }

            $this->users[$ip]->rpmLimit = $this->clientsSettings[$ip]['rpm'] ?? $this->rpmLimit;
            $this->users[$ip]->errorsLimit = $this->clientsSettings[$ip]['errors_limit'] ?? $this->errorsLimit;

            return $this->users[$ip];
        }

    }

}