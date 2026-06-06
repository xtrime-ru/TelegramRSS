<?php

namespace TelegramRSS\AccessControl;

use Revolt\EventLoop;
use TelegramRSS\Config;

use TelegramRSS\Logger;

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

    /** @var float Interval to remove old clients: 300 seconds */
    private const CLEANUP_INTERVAL_MS = 60.0;
    private int $rpmLimit;
    private int $errorsLimit;

    private int $mediaRpmLimit;
    private int $mediaErrorsLimit;

    /** @var array<string, array<string, mixed>> */
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
            $memory = '';
            foreach ($this->{$object} as $key => $value) {
                $memory .= serialize([$key=>$value]) . PHP_EOL;
            }
            $descriptor->write($memory);
            $descriptor->close();
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

    public function getOrCreateUser(string $clientKey, string $type = 'default'): User
    {
        $settings = $this->clientsSettings[$clientKey] ?? [];

        if ($type === 'media') {
            if (!isset($this->mediaUsers[$clientKey])) {
                $this->mediaUsers[$clientKey] = new User();
            }

            $this->mediaUsers[$clientKey]->rpmLimit = $settings['media_rpm'] ?? $settings['rpm'] ?? $this->mediaRpmLimit;
            $this->mediaUsers[$clientKey]->errorsLimit = $settings['media_errors_limit'] ?? $settings['errors_limit'] ?? $this->mediaErrorsLimit;

            return $this->mediaUsers[$clientKey];
        } else {
            if (!isset($this->users[$clientKey])) {
                $this->users[$clientKey] = new User();
            }

            $this->users[$clientKey]->rpmLimit = $settings['rpm'] ?? $this->rpmLimit;
            $this->users[$clientKey]->errorsLimit = $settings['errors_limit'] ?? $this->errorsLimit;

            return $this->users[$clientKey];
        }
    }

    public function checkAuth(?string $authorizationHeader = null): ?string
    {
        return $this->authenticateBasicAuth($authorizationHeader);
    }

    public function authenticateBasicAuth(?string $authorizationHeader): ?string
    {
        $credentials = $this->parseBasicAuthHeader($authorizationHeader);
        if ($credentials === null) {
            return null;
        }

        [$username, $password] = $credentials;
        $settings = $this->clientsSettings[$username] ?? [];
        $expectedPassword = $settings['password'] ?? null;

        if ($expectedPassword > '')  {
            if ($password === $expectedPassword) {
                return $username;
            }
            Logger::getInstance()->notice('Invalid Basic Auth password', ['header' => $authorizationHeader, 'username' => $username, 'password' => $password]);
        } else {
            Logger::getInstance()->notice('Basic Auth password not set', ['username' => $username]);
        }

        return null;
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private function parseBasicAuthHeader(?string $authorizationHeader): ?array
    {
        if ($authorizationHeader === null || $authorizationHeader === '') {
            return null;
        }

        \sscanf($authorizationHeader, "Basic %s", $encodedPassword);
        if (!$encodedPassword) {
            return null;
        }


        $decoded = base64_decode($encodedPassword, true);
        if ($decoded === false || !str_contains($decoded, ':')) {
            Logger::getInstance()->notice('Invalid Basic Auth header', ['header' => $authorizationHeader, 'decoded' => $decoded]);
            return null;
        }

        [$username, $password] = explode(':', $decoded, 2);
        if ($username === '') {
            Logger::getInstance()->notice('Invalid Basic Auth username', ['header' => $authorizationHeader, 'decoded' => $decoded]);
            return null;
        }

        return [$username, $password];
    }

}