<?php

declare(strict_types=1);

namespace MoodSwings\Tests\Maintenance;

use MoodSwings\Maintenance\MaintenanceGate;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

final class MaintenanceGateTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $host = getenv('TEST_DB_HOST') ?: '127.0.0.1';
        $port = getenv('TEST_DB_PORT') ?: '3306';
        $name = getenv('TEST_DB_NAME') ?: 'moodswings_test';
        $user = getenv('TEST_DB_USER') ?: 'root';
        $password = getenv('TEST_DB_PASSWORD') ?: '';

        try {
            $pdo = new PDO(
                "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
                $user,
                $password,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
        } catch (PDOException $e) {
            self::markTestSkipped('No test MySQL database available: ' . $e->getMessage());
        }

        putenv("DB_HOST={$host}");
        putenv("DB_PORT={$port}");
        putenv("DB_NAME={$name}");
        putenv("DB_USER={$user}");
        putenv("DB_PASSWORD={$password}");

        $this->pdo = $pdo;

        // schema_version is dropped/recreated (not just truncated) rather
        // than assumed-present, since "the table doesn't exist" is itself
        // one of the states under test here -- unlike every other
        // integration test's setUp(), which can assume migrations already
        // ran.
        $this->pdo->exec('DROP TABLE IF EXISTS schema_version');
    }

    protected function tearDown(): void
    {
        // Leave the shared TEST_DB seeded and valid so other
        // tests/`composer migrate` runs aren't left with a missing table.
        $this->pdo->exec('DROP TABLE IF EXISTS schema_version');
        $this->pdo->exec(
            'CREATE TABLE schema_version (
                id TINYINT UNSIGNED NOT NULL PRIMARY KEY DEFAULT 1,
                version VARCHAR(20) NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $this->pdo->exec("INSERT INTO schema_version (id, version) VALUES (1, '0.2.0')");
    }

    private function createSchemaVersionTable(): void
    {
        $this->pdo->exec(
            'CREATE TABLE schema_version (
                id TINYINT UNSIGNED NOT NULL PRIMARY KEY DEFAULT 1,
                version VARCHAR(20) NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    public function testTableMissingFailsClosed(): void
    {
        self::assertNotNull(MaintenanceGate::check('0.2.0'));
    }

    public function testMatchingVersionAllowsTraffic(): void
    {
        $this->createSchemaVersionTable();
        $this->pdo->exec("INSERT INTO schema_version (id, version) VALUES (1, '0.2.0')");

        self::assertNull(MaintenanceGate::check('0.2.0'));
    }

    public function testMismatchedVersionShowsMaintenance(): void
    {
        $this->createSchemaVersionTable();
        $this->pdo->exec("INSERT INTO schema_version (id, version) VALUES (1, '0.1.0')");

        self::assertNotNull(MaintenanceGate::check('0.2.0'));
    }

    public function testEmptyDeployedVersionFailsOpen(): void
    {
        // No schema_version table exists at all in this state, yet an
        // empty deployed-version string must still resolve to "allow
        // traffic" -- the file-read failure takes priority over the DB
        // check, since it can't determine what to compare against.
        self::assertNull(MaintenanceGate::check(''));
    }

    public function testEmptyTableFailsClosed(): void
    {
        $this->createSchemaVersionTable();

        self::assertNotNull(MaintenanceGate::check('0.2.0'));
    }

    public function testWhitespaceAroundStoredVersionIsTolerated(): void
    {
        $this->createSchemaVersionTable();
        $this->pdo->exec("INSERT INTO schema_version (id, version) VALUES (1, ' 0.2.0 ')");

        self::assertNull(MaintenanceGate::check('0.2.0'));
    }

    /**
     * Exercises the real deployedVersion()/activeMessage() path (not just
     * the injected-string check()) against the actual repo-root VERSION
     * file, to guard against the VERSION-path-resolution depth bug this
     * class's docblock describes.
     */
    public function testActiveMessageReadsTheRealVersionFile(): void
    {
        $realVersion = MaintenanceGate::deployedVersion();
        self::assertNotSame('', $realVersion, 'MaintenanceGate could not locate the repo-root VERSION file');

        $this->createSchemaVersionTable();
        $stmt = $this->pdo->prepare('INSERT INTO schema_version (id, version) VALUES (1, :version)');
        $stmt->execute(['version' => $realVersion]);

        self::assertNull(MaintenanceGate::activeMessage());
    }
}
