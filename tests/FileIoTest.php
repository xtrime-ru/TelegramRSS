<?php

namespace TelegramRSS\Tests;

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
