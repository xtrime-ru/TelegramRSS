<?php

namespace TelegramRSS\AccessControl;

use Revolt\EventLoop;
use TelegramRSS\Config;
use TelegramRSS\Logger;

use TelegramRSS\Cache\JsonlFileStorage;

use function Amp\File\isFile;

class AccessControl
{
    use JsonlFileStorage;

    /** @var array<array-key, User> */
    private array $users = [];
    /** @var array<array-key, User> */
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

    /** @var array<array-key, array{rpm?: int, media_rpm?: int, errors_limit?: int, media_errors_limit?: int, password?: string}> */
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

    private function saveUsers(): void
    {
        foreach (self::FILES as $path => $object) {
            $records = (function () use ($object): \Generator {
                foreach ($this->{$object} as $key => $user) {
                    yield [$key => $user->__serialize()];
                }
            })();
            $this->writeCacheFile($path, $records);
        }
    }

    private function loadUsers(): void
    {
        foreach (self::FILES as $path => $object) {
            if (!isFile($path)) {
                continue;
            }
            foreach ($this->readCacheLines($path) as $line) {
                foreach (self::decodeUserRecord(trim($line)) as $key => $user) {
                    $this->{$object}[$key] = $this->configuredUser($key, $object === 'mediaUsers', $user);
                }
            }
        }
    }

    /** @return array<array-key, User> */
    private static function decodeUserRecord(string $line): array
    {
        try {
            $legacy = str_starts_with($line, 'a:');
            $record = $legacy
                ? @unserialize($line, ['allowed_classes' => false])
                : json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($record)) {
                return [];
            }
            $users = [];
            foreach ($record as $key => $data) {
                if ($legacy) {
                    $data = (array) $data;
                    if (($data['__PHP_Incomplete_Class_Name'] ?? null) !== User::class) {
                        continue;
                    }
                    $prefix = "\0" . User::class . "\0";
                    // Only the original one-user PHP records are eligible for conversion.
                    if (count($record) !== 1 || !isset($data[$prefix . 'requests'])) {
                        continue;
                    }
                    $data = [
                        'rpmLimit' => $data['rpmLimit'],
                        'errorsLimit' => $data['errorsLimit'],
                        'errors' => $data['errors'],
                        'banLastDuration' => $data['banLastDuration'],
                        'banUntilTs' => $data[$prefix . 'banUntilTs'],
                        'lastRequestTs' => max($data[$prefix . 'requests'] ?: [0]),
                    ];
                }
                if (!is_array($data)) {
                    continue;
                }
                $user = new User();
                $user->__unserialize($data);
                $users[$key] = $user;
            }
            return $users;
        } catch (\Throwable) {
            return []; // A malformed line must not prevent the remaining users from loading.
        }
    }

    /**
     * @param 'default'|'media' $type
     */
    public function getOrCreateUser(string $clientKey, string $type = 'default'): User
    {
        $media = $type === 'media';
        $collection = $media ? 'mediaUsers' : 'users';
        return $this->{$collection}[$clientKey] ??= $this->configuredUser($clientKey, $media);
    }

    private function configuredUser(string $clientKey, bool $media, ?User $user = null): User
    {
        $settings = $this->clientsSettings[$clientKey] ?? [];
        $prefix = $media ? 'media_' : '';
        $rpm = (int) ($settings[$prefix . 'rpm'] ?? $settings['rpm'] ?? ($media ? $this->mediaRpmLimit : $this->rpmLimit));
        $errors = (int) ($settings[$prefix . 'errors_limit'] ?? $settings['errors_limit'] ?? ($media ? $this->mediaErrorsLimit : $this->errorsLimit));
        return $user ? $user->configure($rpm, $errors) : new User($rpm, $errors);
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
            Logger::getInstance()->notice('Invalid Basic Auth password', ['username' => $username]);
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
            Logger::getInstance()->notice('Invalid Basic Auth header');
            return null;
        }

        [$username, $password] = explode(':', $decoded, 2);
        if ($username === '') {
            Logger::getInstance()->notice('Invalid Basic Auth username');
            return null;
        }

        return [$username, $password];
    }

}
