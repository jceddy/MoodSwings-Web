#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use MoodSwings\Database\Connection;

$migrationsDir = dirname(__DIR__, 2) . '/database/migrations';
$pdo = Connection::get();

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS schema_migrations (
        migration VARCHAR(255) NOT NULL,
        applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (migration)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
);

$applied = $pdo->query('SELECT migration FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);

$files = glob($migrationsDir . '/*.sql') ?: [];
sort($files, SORT_STRING);

$appliedCount = 0;

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

    echo "Applied {$name}\n";
    $appliedCount++;
}

echo $appliedCount === 0 ? "Already up to date.\n" : "Applied {$appliedCount} migration(s).\n";
