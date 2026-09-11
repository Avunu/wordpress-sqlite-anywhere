<?php

declare(strict_types=1);

namespace SqliteAnywhere\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SqliteAnywhere\Config;

#[CoversClass(Config::class)]
final class ConfigTest extends TestCase
{
    private const string ENV = 'SQLITE_ANYWHERE_TEST_ENV';

    protected function tearDown(): void
    {
        putenv(self::ENV);
    }

    public function testDefaultWhenNothingIsSet(): void
    {
        self::assertNull(Config::get(self::ENV));
        self::assertSame('fallback', Config::get(self::ENV, 'fallback'));
    }

    public function testNonEmptyEnvironmentVariableWins(): void
    {
        putenv(self::ENV . '=from-env');

        self::assertSame('from-env', Config::get(self::ENV, 'fallback'));
    }

    public function testEmptyEnvironmentVariableIsUnset(): void
    {
        putenv(self::ENV . '=');

        self::assertSame('fallback', Config::get(self::ENV, 'fallback'));
    }

    public function testConstantBeatsEnvironment(): void
    {
        putenv(self::ENV . '=from-env');
        define('SQLITE_ANYWHERE_TEST_CONSTANT', 'from-constant');
        putenv('SQLITE_ANYWHERE_TEST_CONSTANT=from-env');

        self::assertSame('from-constant', Config::get('SQLITE_ANYWHERE_TEST_CONSTANT', 'fallback'));
        putenv('SQLITE_ANYWHERE_TEST_CONSTANT');
    }

    public function testTypedAccessors(): void
    {
        putenv(self::ENV . '=30000');
        self::assertSame(30000, Config::int(self::ENV, 1));
        self::assertSame('30000', Config::string(self::ENV));
        self::assertSame('30000', Config::nullableString(self::ENV));

        putenv(self::ENV . '=not-a-number');
        self::assertSame(1, Config::int(self::ENV, 1));

        putenv(self::ENV . '=off');
        self::assertFalse(Config::bool(self::ENV, true));

        putenv(self::ENV . '=1');
        self::assertTrue(Config::bool(self::ENV, false));

        putenv(self::ENV . '=maybe');
        self::assertTrue(Config::bool(self::ENV, true));

        putenv(self::ENV);
        self::assertNull(Config::nullableString(self::ENV));
        self::assertSame('', Config::string(self::ENV));
        self::assertTrue(Config::bool(self::ENV, true));
    }
}
