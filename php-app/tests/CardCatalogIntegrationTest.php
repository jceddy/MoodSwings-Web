<?php

declare(strict_types=1);

namespace MoodSwings\Tests;

use MoodSwings\Repository\CardRepository;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the `cards` reference table (seeded by
 * 0003_create_card_catalog.sql) loaded correctly. This is read-only
 * catalog data -- unlike the other integration tests, there's nothing to
 * truncate/seed in setUp(); it's just there once migrations have run.
 */
final class CardCatalogIntegrationTest extends TestCase
{
    private CardRepository $cards;

    protected function setUp(): void
    {
        $host = getenv('TEST_DB_HOST') ?: '127.0.0.1';
        $port = getenv('TEST_DB_PORT') ?: '3306';
        $name = getenv('TEST_DB_NAME') ?: 'moodswings_test';
        $user = getenv('TEST_DB_USER') ?: 'root';
        $password = getenv('TEST_DB_PASSWORD') ?: '';

        try {
            new PDO(
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

        $this->cards = new CardRepository();
    }

    public function testCatalogHasExactlyOneHundredThirtyThreeCards(): void
    {
        self::assertCount(133, $this->cards->all());
    }

    public function testColorCountsMatchTheOfficialGallery(): void
    {
        self::assertCount(26, $this->cards->findByColor('white'));
        self::assertCount(26, $this->cards->findByColor('blue'));
        self::assertCount(27, $this->cards->findByColor('black'));
        self::assertCount(27, $this->cards->findByColor('red'));
        self::assertCount(27, $this->cards->findByColor('green'));
    }

    public function testNoDuplicateNamesOrEffectKeys(): void
    {
        $all = $this->cards->all();

        $names = array_column($all, 'name');
        $effectKeys = array_column($all, 'effect_key');

        self::assertCount(count($names), array_unique($names));
        self::assertCount(count($effectKeys), array_unique($effectKeys));
    }

    public function testFindByIdReturnsExpectedCard(): void
    {
        $altruism = $this->cards->findById(1);

        self::assertNotNull($altruism);
        self::assertSame('Altruism', $altruism['name']);
        self::assertSame('white', $altruism['color']);
        self::assertSame('rare', $altruism['rarity']);
        self::assertSame(3, (int) $altruism['base_value']);
        self::assertSame(6, (int) $altruism['alt_value']);
        self::assertSame(1, (int) $altruism['has_after_playing_ability']);
        self::assertSame(0, (int) $altruism['has_while_in_play_ability']);
    }

    public function testFindByEffectKeyReturnsExpectedCard(): void
    {
        $creativity = $this->cards->findByEffectKey('creativity');

        self::assertNotNull($creativity);
        self::assertSame('Creativity', $creativity['name']);
        self::assertSame(0, (int) $creativity['has_to_play_ability']);
        self::assertSame(0, (int) $creativity['has_while_in_play_ability']);
        self::assertSame(0, (int) $creativity['has_after_playing_ability']);
    }

    public function testFindByIdReturnsNullForUnknownCard(): void
    {
        self::assertNull($this->cards->findById(9999));
    }

    public function testVanillaCommonsHaveNoAbilityFlagsSet(): void
    {
        foreach (['apathy', 'boredom', 'complacency', 'indifference', 'laziness'] as $effectKey) {
            $card = $this->cards->findByEffectKey($effectKey);

            self::assertNotNull($card, "Expected a vanilla common with effect_key {$effectKey}");
            self::assertSame(4, (int) $card['base_value']);
            self::assertNull($card['alt_value']);
            self::assertSame(0, (int) $card['has_to_play_ability']);
            self::assertSame(0, (int) $card['has_while_in_play_ability']);
            self::assertSame(0, (int) $card['has_after_playing_ability']);
        }
    }
}
