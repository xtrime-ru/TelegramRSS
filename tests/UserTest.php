<?php

namespace TelegramRSS\Tests;

use Amp\File\Driver\BlockingFilesystemDriver;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Symfony\Bridge\PhpUnit\ClockMock;
use TelegramRSS\AccessControl\AccessControl;

use TelegramRSS\AccessControl\User;
use TelegramRSS\Config;

use function Amp\File\filesystem;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class UserTest extends TestCase
{
    protected function setUp(): void
    {
        define('ROOT_DIR', sys_get_temp_dir() . '/tg-rss-test-' . bin2hex(random_bytes(6)));
        mkdir(ROOT_DIR . '/cache', recursive: true);
        file_put_contents(ROOT_DIR . '/config.php', '<?php return [];');
        Config::getInstance()->config['access'] = ['rpm' => 2, 'media_rpm' => 3, 'errors_limit' => -1,
            'media_errors_limit' => -1, 'clients_settings' => [], 'forbidden_peer_regex' => '', 'cache_peer_errors_regex' => 'PRIVATE'];
        filesystem(new BlockingFilesystemDriver());
        foreach ([User::class, \Symfony\Component\RateLimiter\Policy\SlidingWindowLimiter::class,
            \Symfony\Component\RateLimiter\Storage\InMemoryStorage::class] as $class) {
            ClockMock::register($class);
        }
        ClockMock::withClockMock(1000);
    }

    protected function tearDown(): void
    {
        ClockMock::withClockMock(false);
        foreach (glob(ROOT_DIR . '/cache/*') as $path) {
            unlink($path);
        }
        unlink(ROOT_DIR . '/config.php');
        rmdir(ROOT_DIR . '/cache');
        rmdir(ROOT_DIR);
    }

    public function testQuotasAreIndependentForClientsAndMediaWithConfiguredOverrides(): void
    {
        Config::getInstance()->config['access']['clients_settings'] = [
            'subscriber' => ['rpm' => 1, 'media_rpm' => 2], 'blocked' => ['rpm' => 0], 'unlimited' => ['rpm' => -1],
        ];
        $access = new AccessControl();
        self::assertSame([false, false, true], $this->consume($access->getOrCreateUser('first'), 3));
        self::assertSame([false, false, false, true], $this->consume($access->getOrCreateUser('first', 'media'), 4));
        self::assertSame([false], $this->consume($access->getOrCreateUser('other'), 1));
        self::assertSame([false, true], $this->consume($access->getOrCreateUser('subscriber'), 2));
        self::assertSame([false, false, true], $this->consume($access->getOrCreateUser('subscriber', 'media'), 3));
        self::assertTrue($access->getOrCreateUser('blocked')->isBanned());
        self::assertSame(array_fill(0, 5, false), $this->consume($access->getOrCreateUser('unlimited'), 5));
        ClockMock::sleep(121);
        self::assertSame([false, false, true], $this->consume($access->getOrCreateUser('first'), 3));
    }

    public function testErrorThresholdBansTemporarilyAndRepeatedAbuseIncreasesTheBan(): void
    {
        $user = new User(10, 1);
        $user->addError('first', '/rss/test');
        self::assertFalse($user->isBanned());
        $user->addError('second', '/rss/test');
        self::assertSame('00:01:00', $user->getBanDuration());
        ClockMock::sleep(61);
        self::assertFalse($user->isBanned());
        $user->addError('third', '/rss/test');
        $user->addError('fourth', '/rss/test');
        self::assertSame('00:05:00', $user->getBanDuration());
    }

    public function testRestartKeepsErrorsAndBansButResetsQuotasAndAppliesCurrentSettings(): void
    {
        $access = new AccessControl();
        $feed = $access->getOrCreateUser('reader');
        $this->consume($feed, 3);
        $feed->addError("first line\nsecond line", '/rss/test');
        $access->getOrCreateUser('reader', 'media')->addBan();
        // Trigger the periodic dump without waiting for the real event-loop timer.
        (new ReflectionMethod(AccessControl::class, 'saveUsers'))->invoke($access);
        $unchanged = new AccessControl();
        self::assertSame([false, false, true], $this->consume($unchanged->getOrCreateUser('reader'), 3));
        Config::getInstance()->config['access']['clients_settings'] = ['reader' => ['rpm' => 3, 'media_rpm' => 4]];
        $restored = new AccessControl();
        self::assertSame($feed->errors, $restored->getOrCreateUser('reader')->errors);
        self::assertSame([false, false, false, true], $this->consume($restored->getOrCreateUser('reader'), 4));
        $media = $restored->getOrCreateUser('reader', 'media');
        self::assertTrue($media->isBanned());
        ClockMock::sleep(61);
        self::assertFalse($media->isBanned());
        self::assertSame([false, false, false, false, true], $this->consume($media, 5));
    }

    public function testOriginalAndJSONLRecordsLoadDespiteBrokenLines(): void
    {
        $private = "\0" . User::class . "\0";
        $fields = ['rpmLimit' => 15, 'errorsLimit' => 0, 'rpm' => 2,
            $private . 'requests' => [999, 1000], 'errors' => [], $private . 'permanentBan' => false,
            'banLastDuration' => 60, $private . 'banUntilTs' => 1030];
        $object = 'O:' . strlen(User::class) . ':"' . User::class . '":' . count($fields) . ':{';
        foreach ($fields as $field => $value) {
            $object .= serialize($field) . serialize($value);
        }
        $modern = new User();
        $modern->addError("saved\nerror", '/rss/test');
        $legacy = 'a:1:{s:6:"legacy";' . $object . '}}';
        file_put_contents(ROOT_DIR . '/cache/users.cache', $legacy . "\r\nnot-json\na:broken\n"
            . serialize(['intermediate' => $modern]) . "\n"
            . json_encode(['modern' => $modern->__serialize()], JSON_THROW_ON_ERROR));
        $access = new AccessControl();
        $restored = $access->getOrCreateUser('legacy');
        self::assertTrue($restored->isBanned());
        self::assertSame(1000, $restored->getLastAccessTs());
        self::assertSame($modern->errors, $access->getOrCreateUser('modern')->errors);
        self::assertSame([], $access->getOrCreateUser('intermediate')->errors);
        ClockMock::sleep(31);
        self::assertSame([false, false, true], $this->consume($restored, 3));
    }

    /** @return list<bool> */
    private function consume(User $user, int $count): array
    {
        return array_map(static function () use ($user): bool {
            $user->addRequest();
            return $user->isRateLimited();
        }, range(1, $count));
    }
}
