<?php

declare(strict_types=1);

namespace SqliteAnywhere\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SqliteAnywhere\Engine;

#[CoversClass(Engine::class)]
final class EngineTest extends TestCase
{
    private const array VARIABLES = ['DB_ENGINE', 'WP_TURSO_URL', 'WP_D1_PROXY_URL'];

    protected function tearDown(): void
    {
        foreach (self::VARIABLES as $name) {
            putenv($name);
        }
    }

    /**
     * @return iterable<string, array{array<string, string>, Engine|null}>
     */
    public static function environments(): iterable
    {
        yield 'nothing configured: local sqlite' => [[], Engine::Sqlite];
        yield 'explicit sqlite' => [['DB_ENGINE' => 'sqlite'], Engine::Sqlite];
        yield 'explicit turso' => [['DB_ENGINE' => 'turso'], Engine::Turso];
        yield 'explicit d1' => [['DB_ENGINE' => 'd1'], Engine::D1];
        yield 'explicit engine is case-insensitive' => [['DB_ENGINE' => 'Turso'], Engine::Turso];
        yield 'mysql: stand aside' => [['DB_ENGINE' => 'mysql'], null];
        yield 'unknown engine: stand aside' => [['DB_ENGINE' => 'postgres'], null];
        yield 'turso url infers turso' => [['WP_TURSO_URL' => 'libsql://db.turso.io'], Engine::Turso];
        yield 'd1 proxy url infers d1' => [['WP_D1_PROXY_URL' => 'http://d1.internal'], Engine::D1];
        yield 'turso wins over d1 when both are configured' => [
            ['WP_TURSO_URL' => 'libsql://db.turso.io', 'WP_D1_PROXY_URL' => 'http://d1.internal'],
            Engine::Turso,
        ];
        yield 'explicit engine beats inference' => [
            ['DB_ENGINE' => 'sqlite', 'WP_TURSO_URL' => 'libsql://db.turso.io'],
            Engine::Sqlite,
        ];
        yield 'explicit mysql beats inference' => [
            ['DB_ENGINE' => 'mysql', 'WP_D1_PROXY_URL' => 'http://d1.internal'],
            null,
        ];
    }

    /**
     * @param array<string, string> $environment
     */
    #[DataProvider('environments')]
    public function testResolve(array $environment, ?Engine $expected): void
    {
        foreach ($environment as $name => $value) {
            putenv($name . '=' . $value);
        }

        self::assertSame($expected, Engine::resolve());
    }

    public function testRemoteness(): void
    {
        self::assertFalse(Engine::Sqlite->isRemote());
        self::assertTrue(Engine::Turso->isRemote());
        self::assertTrue(Engine::D1->isRemote());
    }

    public function testBackendDirectoryMatchesTheDriverLayout(): void
    {
        self::assertSame('turso', Engine::Turso->backendDirectory());
        self::assertSame('d1', Engine::D1->backendDirectory());
    }
}
