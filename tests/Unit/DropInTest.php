<?php

declare(strict_types=1);

namespace SqliteAnywhere\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use SqliteAnywhere\DropIn;
use SqliteAnywhere\Engine;

/**
 * The drop-in defines process-global constants and requires files, so each
 * routing test runs in its own process against a fake plugin folder whose
 * files only record that they were loaded.
 */
#[CoversClass(DropIn::class)]
final class DropInTest extends TestCase
{
    private string $folder;

    protected function setUp(): void
    {
        $this->folder = sys_get_temp_dir() . '/sqlite-anywhere-' . bin2hex(random_bytes(4));
        $this->fakeFile('wp-includes/sqlite/db.php', '<?php $GLOBALS["loaded"][] = "sqlite/db.php";');
        $this->fakeFile('wp-includes/database/version.php', '<?php $GLOBALS["loaded"][] = "version.php";');
        $this->fakeFile('constants.php', '<?php $GLOBALS["loaded"][] = "constants.php";');
        $this->fakeFile('wp-includes/database/load.php', '<?php $GLOBALS["loaded"][] = "database/load.php";');
        $this->fakeFile('wp-includes/database/turso/load.php', '<?php $GLOBALS["loaded"][] = "turso/load.php";');
        $this->fakeFile('wp-includes/database/d1/load.php', '<?php $GLOBALS["loaded"][] = "d1/load.php";');
        $this->fakeFile('wp-includes/database/remote/load.php', '<?php $GLOBALS["loaded"][] = "remote/load.php";');
        $this->fakeFile('wp-includes/sqlite/class-wp-sqlite-db.php', '<?php $GLOBALS["loaded"][] = "class-wp-sqlite-db.php";');
        $this->fakeFile(
            'wp-includes/sqlite/class-wp-sqlite-remote-db.php',
            '<?php $GLOBALS["loaded"][] = "class-wp-sqlite-remote-db.php";'
            . ' class WP_SQLite_Remote_DB { public function __construct(string $dbname, string $engine, callable $config)'
            . ' { $GLOBALS["remote_db"] = [$dbname, $engine, $config]; } }'
        );
    }

    protected function tearDown(): void
    {
        foreach (['DB_ENGINE', 'DB_NAME', 'WP_TURSO_URL', 'WP_D1_PROXY_URL'] as $name) {
            putenv($name);
        }
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->folder, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->folder);
    }

    #[RunInSeparateProcess]
    public function testMysqlStandsAsideAndDefinesNothing(): void
    {
        putenv('DB_ENGINE=mysql');

        self::assertNull(DropIn::boot($this->folder));
        self::assertFalse(defined('DB_ENGINE'));
        self::assertArrayNotHasKey('loaded', $GLOBALS);
    }

    #[RunInSeparateProcess]
    public function testLocalSqliteTakesUpstreamsPath(): void
    {
        self::assertSame(Engine::Sqlite, DropIn::boot($this->folder));
        self::assertSame('sqlite', constant('DB_ENGINE'));
        self::assertSame('sqlite', constant('DATABASE_TYPE'));
        self::assertSame(['sqlite/db.php'], $GLOBALS['loaded']);
    }

    #[RunInSeparateProcess]
    public function testTursoBootsTheRemoteLayer(): void
    {
        putenv('WP_TURSO_URL=libsql://db.turso.io');
        putenv('DB_NAME=wordpress');

        self::assertSame(Engine::Turso, DropIn::boot($this->folder));
        self::assertSame('turso', constant('DB_ENGINE'));
        self::assertSame(
            [
                'version.php',
                'constants.php',
                'database/load.php',
                'turso/load.php',
                'remote/load.php',
                'class-wp-sqlite-db.php',
                'class-wp-sqlite-remote-db.php',
            ],
            $GLOBALS['loaded']
        );

        self::assertInstanceOf(\WP_SQLite_Remote_DB::class, $GLOBALS['wpdb']);
        [$dbName, $engine, $config] = $GLOBALS['remote_db'];
        self::assertSame('wordpress', $dbName);
        self::assertSame('turso', $engine);
        self::assertIsCallable($config);
        self::assertSame('libsql://db.turso.io', $config('WP_TURSO_URL'));
    }

    #[RunInSeparateProcess]
    public function testD1BootsItsOwnLoader(): void
    {
        putenv('DB_ENGINE=d1');

        self::assertSame(Engine::D1, DropIn::boot($this->folder));
        self::assertContains('d1/load.php', $GLOBALS['loaded']);
        self::assertNotContains('turso/load.php', $GLOBALS['loaded']);
        self::assertNotContains('sqlite/db.php', $GLOBALS['loaded']);
        self::assertSame(['', 'd1'], array_slice($GLOBALS['remote_db'], 0, 2));
    }

    private function fakeFile(string $relative, string $contents): void
    {
        $path = $this->folder . '/' . $relative;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $contents);
    }
}
