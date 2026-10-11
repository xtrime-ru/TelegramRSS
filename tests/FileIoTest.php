<?php

namespace TelegramRSS\Tests;

use Amp\ByteStream\StreamException;
use Amp\File\File;
use Amp\File\FilesystemDriver;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use TelegramRSS\AccessControl\AccessControl;
use TelegramRSS\AccessControl\ForbiddenPeers;
use TelegramRSS\Config;
use TelegramRSS\Logger;

use function Amp\File\filesystem;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class FileIoTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        define('ROOT_DIR', dirname(__DIR__));
        $GLOBALS['options'] = ['docker' => false];
        Config::getInstance()->config = include ROOT_DIR . '/config.php';
        Config::getInstance()->config['access']['cache_peer_errors_regex'] = 'PRIVATE';
        Config::getInstance()->config['access']['forbidden_peer_regex'] = '';
    }

    public function testReadFailureClosesFileAndDoesNotPublishPartialBlocklist(): void
    {
        $path = ROOT_DIR . '/cache/forbidden-peers.csv';
        $error = new StreamException('read failed');
        $file = Mockery::mock(File::class);
        $file->shouldReceive('read')->once()->andReturn('old,PRIVATE_OLD' . "\n");
        $file->shouldReceive('read')->once()->andThrow($error);
        $file->shouldReceive('close')->once();
        $retry = Mockery::mock(File::class);
        $retry->shouldReceive('read')->once()->andReturnNull();
        $retry->shouldReceive('close')->once();
        $driver = Mockery::mock(FilesystemDriver::class);
        $driver->shouldReceive('getStatus')->with($path)->twice()->andReturn(['mode' => 0100000]);
        $driver->shouldReceive('openFile')->with($path, 'rb')->twice()->andReturn($file, $retry);
        filesystem($driver);

        try {
            ForbiddenPeers::check('old');
            self::fail('Expected read failure');
        } catch (StreamException $exception) {
            self::assertSame($error, $exception);
        }
        self::assertNull(ForbiddenPeers::check('old'));
    }

    public function testAppendFailureClosesFileLogsErrorAndKeepsPeerBlockedInMemory(): void
    {
        $path = ROOT_DIR . '/cache/forbidden-peers.csv';
        $error = new StreamException('write failed');
        $file = Mockery::mock(File::class);
        $file->shouldReceive('write')->once()->andThrow($error);
        $file->shouldReceive('close')->once();
        $driver = Mockery::mock(FilesystemDriver::class);
        $driver->shouldReceive('getStatus')->with($path)->once()->andReturnNull();
        $driver->shouldReceive('openFile')->with($path, 'ab')->once()->andReturn($file);
        filesystem($driver);
        $logger = Mockery::mock(Logger::class);
        $logger->shouldReceive('error')->once();
        (new ReflectionProperty(Logger::class, 'instanse'))->setValue(null, $logger);

        ForbiddenPeers::add('@TEST', 'PRIVATE');
        self::assertSame('PRIVATE', ForbiddenPeers::check('test'));
    }

    public function testSnapshotStreamFailureClosesAndCleansUpThenContinues(): void
    {
        $access = (new ReflectionClass(AccessControl::class))->newInstanceWithoutConstructor();
        $user = new \TelegramRSS\AccessControl\User();
        (new ReflectionProperty(AccessControl::class, 'users'))->setValue($access, ['reader' => $user]);
        $error = new \Error('write failed');
        $driver = Mockery::mock(FilesystemDriver::class);
        $path = ROOT_DIR . '/cache/users.cache';
        $file = Mockery::mock(File::class);
        $file->shouldReceive('write')->once()->andThrow($error);
        $file->shouldReceive('close')->once();
        $driver->shouldReceive('openFile')->with($path . '.tmp', 'wb')->once()->andReturn($file);
        $driver->shouldReceive('getStatus')->with($path . '.tmp')->once()->andReturn(['mode' => 0100000]);
        $driver->shouldReceive('deleteFile')->with($path . '.tmp')->once();
        $driver->shouldNotReceive('move')->with($path . '.tmp', $path);
        $next = ROOT_DIR . '/cache/media-users.cache';
        $empty = Mockery::mock(File::class);
        $empty->shouldReceive('close')->once();
        $driver->shouldReceive('openFile')->with($next . '.tmp', 'wb')->once()->andReturn($empty);
        $driver->shouldReceive('move')->with($next . '.tmp', $next)->once();
        $driver->shouldReceive('getStatus')->with($next . '.tmp')->once()->andReturnNull();
        filesystem($driver);
        $logger = Mockery::mock(Logger::class);
        $logger->shouldReceive('error')->once();
        (new ReflectionProperty(Logger::class, 'instanse'))->setValue(null, $logger);

        (new ReflectionMethod(AccessControl::class, 'saveUsers'))->invoke($access);
    }
}
