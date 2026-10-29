<?php

namespace TelegramRSS\Tests;

use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use TelegramRSS\AccessControl\AccessControl;
use TelegramRSS\Logger;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class BasicAuthTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function testOnlyValidCredentialsAuthenticate(): void
    {
        define('ROOT_DIR', dirname(__DIR__));
        $access = (new ReflectionClass(AccessControl::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(AccessControl::class, 'clientsSettings'))->setValue($access, [
            'reader' => ['password' => 'expected-test-password'],
        ]);
        $logger = Mockery::mock(Logger::class);
        $logger->shouldReceive('notice')->twice();
        (new ReflectionProperty(Logger::class, 'instanse'))->setValue(null, $logger);

        self::assertSame('reader', $access->authenticateBasicAuth('Basic ' . base64_encode('reader:expected-test-password')));
        self::assertNull($access->authenticateBasicAuth('Basic ' . base64_encode('reader:wrong-test-password')));
        self::assertNull($access->authenticateBasicAuth('Basic ' . base64_encode('unknown:expected-test-password')));
    }
}
