<?php

declare(strict_types=1);

namespace MoodSwings\Database;

/**
 * Applies pending database/migrations/*.sql files, tracked in the
 * schema_migrations table -- shared by bin/migrate.php (local/CLI) and
 * the HTTP-triggered POST /migrate route (production, which has no shell
 * access of its own -- see "Applying migrations" in database/README.md).
 *
 * Locating database/migrations mirrors MaintenanceGate::deployedVersion()'s
 * own two-candidate-depth lookup: this class sits at a different depth
 * relative to the repo root in a local checkout (php-app/src/Database/...,
 * three levels above the repo root) than it does once deployed
 * (dist/src/Database/..., two levels above dist/ -- with
 * database/migrations copied to dist/database/migrations as a sibling of
 * dist/src, see .github/workflows/deploy*.yml's "Assemble deploy
 * artifact" step).
 */
final class MigrationRunner
{
    /**
     * @param callable(string): void|null $onApplied invoked with each
     *     migration's filename immediately after it applies, before
     *     moving on to the next one -- lets a CLI caller (bin/migrate.php)
     *     echo progress in real time rather than only after every pending
     *     migration has already run
     * @return string[] filenames actually applied this run, in the order
     *     applied (empty if already up to date)
     */
    public static function applyPending(\PDO $pdo, ?string $migrationsDir = null, ?callable $onApplied = null): array
    {
        $migrationsDir ??= self::defaultMigrationsDir();

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                migration VARCHAR(255) NOT NULL,
                applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (migration)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $applied = $pdo->query('SELECT migration FROM schema_migrations')->fetchAll(\PDO::FETCH_COLUMN);

        $files = glob($migrationsDir . '/*.sql') ?: [];
        sort($files, SORT_STRING);

        $appliedNow = [];

        foreach ($files as $file) {
            $name = basename($file);

            if (in_array($name, $applied, true)) {
                continue;
            }

            $lines = file($file, FILE_IGNORE_NEW_LINES);
            $withoutComments = implode("\n", array_filter($lines, fn (string $line) => !str_starts_with(trim($line), '--')));

            // Naive statement split: fine for this project's DDL-only migrations,
            // which never need a semicolon inside a string, trigger, or procedure.
            $statements = array_filter(array_map('trim', explode(';', $withoutComments)));

            foreach ($statements as $statement) {
                $pdo->exec($statement);
            }

            $stmt = $pdo->prepare('INSERT INTO schema_migrations (migration) VALUES (:migration)');
            $stmt->execute(['migration' => $name]);

            $appliedNow[] = $name;

            if ($onApplied !== null) {
                $onApplied($name);
            }
        }

        return $appliedNow;
    }

    private static function defaultMigrationsDir(): string
    {
        foreach ([dirname(__DIR__, 3) . '/database/migrations', dirname(__DIR__, 2) . '/database/migrations'] as $candidate) {
            if (is_dir($candidate)) {
                return $candidate;
            }
        }

        throw new \RuntimeException('Could not locate the database/migrations directory');
    }
}
