<?php

declare(strict_types=1);

namespace MoodSwings\Tests\Rules;

use MoodSwings\Rules\ChaosDefaultEffectRegistry;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * Chaos Draft (issue #405): a registered ChaosMoodEffect implementation
 * must exist for every one of the 133 chaos_effects.effect_key rows
 * seeded by migration 0183 -- this is the cheap, fast-running guardrail
 * against a future chaos_effect_key silently falling through to
 * EffectNotImplementedException at play time. See
 * ChaosDraftCompositionTest.php/BoardStateRepositoryChaosDraftTest.php/
 * ChaosDraftOfferIntegrationTest.php for behavioral coverage of the
 * composition pipeline and persistence itself.
 */
final class ChaosDefaultEffectRegistryTest extends TestCase
{
    public function testEveryChaosEffectKeyFromOneToOneThirtyThreeIsRegistered(): void
    {
        $registry = ChaosDefaultEffectRegistry::build();

        $missing = [];
        for ($i = 1; $i <= 133; $i++) {
            $key = sprintf('chaos_%03d', $i);
            if (!$registry->has($key)) {
                $missing[] = $key;
            }
        }

        self::assertSame([], $missing, 'Missing ChaosMoodEffect registrations: ' . implode(', ', $missing));
    }

    public function testNoUnexpectedKeysBeyondTheCuratedOneThirtyThreeEffectPool(): void
    {
        $registry = ChaosDefaultEffectRegistry::build();

        self::assertFalse($registry->has('chaos_134'));
        self::assertFalse($registry->has('chaos_000'));
    }

    /**
     * Cross-checks the registry directly against migration 0183's own
     * seeded chaos_effects.effect_key rows -- catches drift a hardcoded
     * "1 to 133" loop above couldn't (e.g. a future migration renumbering
     * or renaming an effect_key without updating the registry).
     */
    public function testEveryEffectKeyActuallyStoredInTheDatabaseIsRegistered(): void
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
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
            );
        } catch (PDOException $e) {
            self::markTestSkipped('No test MySQL database available: ' . $e->getMessage());
        }

        $effectKeys = $pdo->query('SELECT effect_key FROM chaos_effects')->fetchAll(PDO::FETCH_COLUMN);
        self::assertCount(133, $effectKeys, 'chaos_effects should hold exactly the curated 133-effect pool');

        $registry = ChaosDefaultEffectRegistry::build();
        $missing = array_filter($effectKeys, static fn (string $key) => !$registry->has($key));
        self::assertSame([], array_values($missing), 'Missing ChaosMoodEffect registrations for DB-seeded keys: ' . implode(', ', $missing));
    }

    /**
     * A bug caught live: migration 0183's own chaos_010 row carried a
     * stray trailing apostrophe in its rules_text (an extra `'''` at the
     * end of the SQL string literal, rather than the intended closing
     * `'`) -- rendered to a player as "...into the discard pile.'", a
     * visible typo nothing else in this test file would have caught since
     * the other two tests above only check effect_key coverage, never
     * rules_text content.
     */
    public function testNoRulesTextEndsWithAStrayApostrophe(): void
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
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
            );
        } catch (PDOException $e) {
            self::markTestSkipped('No test MySQL database available: ' . $e->getMessage());
        }

        $rows = $pdo->query('SELECT effect_key, rules_text FROM chaos_effects')->fetchAll();
        $offenders = array_values(array_filter(
            $rows,
            static fn (array $row): bool => str_ends_with((string) $row['rules_text'], "'"),
        ));

        self::assertSame([], $offenders, 'chaos_effects.rules_text should never end with a stray apostrophe');
    }
}
