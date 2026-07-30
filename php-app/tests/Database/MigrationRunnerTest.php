<?php

declare(strict_types=1);

namespace MoodSwings\Tests\Database;

use MoodSwings\Database\MigrationRunner;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * Exercises MigrationRunner::applyPending() against its own scratch
 * fixture directory rather than the real database/migrations/ -- that
 * directory only ever grows, and every one of its files is already
 * applied to TEST_DB by the time any test runs, so there'd be nothing
 * left to actually apply. Fixture filenames are namespaced
 * (zzz_migration_runner_test_*) so they can never collide with a real
 * migration's own name, and their SQL content is an inert `DO 0;` (no
 * actual schema change) so nothing needs cleaning up in the DB itself
 * beyond the schema_migrations bookkeeping rows this test adds.
 */
final class MigrationRunnerTest extends TestCase
{
    private PDO $pdo;
    private string $migrationsDir;

    protected function setUp(): void
    {
        $host = getenv('TEST_DB_HOST') ?: '127.0.0.1';
        $port = getenv('TEST_DB_PORT') ?: '3306';
        $name = getenv('TEST_DB_NAME') ?: 'moodswings_test';
        $user = getenv('TEST_DB_USER') ?: 'root';
        $password = getenv('TEST_DB_PASSWORD') ?: '';

        try {
            $this->pdo = new PDO(
                "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
                $user,
                $password,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
        } catch (PDOException $e) {
            self::markTestSkipped('No test MySQL database available: ' . $e->getMessage());
        }

        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                migration VARCHAR(255) NOT NULL,
                applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (migration)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $this->cleanUpFixtureRows();

        $this->migrationsDir = sys_get_temp_dir() . '/migration_runner_test_' . uniqid();
        mkdir($this->migrationsDir);
        file_put_contents($this->migrationsDir . '/zzz_migration_runner_test_a.sql', "DO 0;\n");
        // Includes a stray semicolon inside a `--` comment line, and a
        // second real statement -- both are exactly what bin/migrate.php's
        // own docblock warns a real migration must avoid in its DDL, but
        // here specifically verifies the comment-stripping happens BEFORE
        // the naive semicolon split, so a comment's own punctuation can
        // never fracture a statement.
        file_put_contents(
            $this->migrationsDir . '/zzz_migration_runner_test_b.sql',
            "-- a comment; with a stray semicolon\nDO 0;\nDO 1;\n"
        );
    }

    protected function tearDown(): void
    {
        $this->cleanUpFixtureRows();

        foreach (glob($this->migrationsDir . '/*.sql') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->migrationsDir);
    }

    private function cleanUpFixtureRows(): void
    {
        $this->pdo->exec("DELETE FROM schema_migrations WHERE migration LIKE 'zzz_migration_runner_test_%'");
    }

    public function testAppliesPendingMigrationsInOrderAndRecordsThem(): void
    {
        $applied = MigrationRunner::applyPending($this->pdo, $this->migrationsDir);

        self::assertSame(
            ['zzz_migration_runner_test_a.sql', 'zzz_migration_runner_test_b.sql'],
            $applied
        );

        $recorded = $this->pdo
            ->query("SELECT migration FROM schema_migrations WHERE migration LIKE 'zzz_migration_runner_test_%' ORDER BY migration")
            ->fetchAll(PDO::FETCH_COLUMN);
        self::assertSame(['zzz_migration_runner_test_a.sql', 'zzz_migration_runner_test_b.sql'], $recorded);
    }

    public function testAlreadyAppliedMigrationsAreSkippedOnASecondRun(): void
    {
        MigrationRunner::applyPending($this->pdo, $this->migrationsDir);

        $secondRun = MigrationRunner::applyPending($this->pdo, $this->migrationsDir);

        self::assertSame([], $secondRun);
    }

    public function testOnAppliedCallbackFiresForEachMigrationInOrder(): void
    {
        $seen = [];

        MigrationRunner::applyPending($this->pdo, $this->migrationsDir, function (string $name) use (&$seen): void {
            $seen[] = $name;
        });

        self::assertSame(
            ['zzz_migration_runner_test_a.sql', 'zzz_migration_runner_test_b.sql'],
            $seen
        );
    }

    public function testOnlyNewlyAddedMigrationsApplyOnALaterRun(): void
    {
        MigrationRunner::applyPending($this->pdo, $this->migrationsDir);

        file_put_contents($this->migrationsDir . '/zzz_migration_runner_test_c.sql', "DO 0;\n");

        $applied = MigrationRunner::applyPending($this->pdo, $this->migrationsDir);

        self::assertSame(['zzz_migration_runner_test_c.sql'], $applied);
    }
}
