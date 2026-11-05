<?php

declare(strict_types=1);

namespace MoodSwings\Tests\Game;

use MoodSwings\Deck\UserDecklistService;
use MoodSwings\Friends\FriendshipService;
use MoodSwings\Game\BoardStateRepository;
use MoodSwings\Game\CardCatalog;
use MoodSwings\Game\Exceptions\GameStateException;
use MoodSwings\Game\GameService;
use MoodSwings\Game\ReplayStateBuilder;
use MoodSwings\Repository\FriendshipRepository;
use MoodSwings\Repository\UserDecklistRepository;
use MoodSwings\Repository\UserRepository;
use MoodSwings\Rules\DefaultEffectRegistry;
use MoodSwings\Rules\MoodPlayService;
use MoodSwings\Rules\RoundScorer;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * Custom card sets (migration 0496): a set flagged is_custom is only visible to players who opted
 * into custom content, a game's random pools can be built from any chosen set(s), and the Daily/
 * Weekly Sealed Pools always stay original-set only. These tests give the custom set XEMB a
 * stand-in catalog of cards with ids 900+ (removed again afterwards), so they pass whatever real
 * cards the set currently holds.
 */
final class CustomCardSetIntegrationTest extends TestCase
{
    private const FIRST_TEST_CARD_ID = 900;

    private PDO $pdo;
    private GameService $games;
    /** @var array<int, array<string, mixed>> the real XEMB card_sets rows, set aside while each test runs (see setUp()) */
    private array $realCustomSetRows = [];

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
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
            );
        } catch (PDOException $e) {
            self::markTestSkipped('No test MySQL database available: ' . $e->getMessage());
        }

        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ([
            'game_events', 'game_pending_decisions', 'game_pending_decision_batches', 'game_cards', 'game_round_scores',
            'game_rounds', 'game_players', 'games', 'game_matches', 'draft_pile_stage_picks', 'draft_round_picks',
            'draft_winston_state', 'draft_grid_state', 'draft_rotisserie_state', 'draft_tiered_rotisserie_state',
            'draft_match_players', 'draft_matches', 'periodic_sealed_pools', 'users',
        ] as $table) {
            $pdo->exec("TRUNCATE TABLE {$table}");
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

        putenv("DB_HOST={$host}");
        putenv("DB_PORT={$port}");
        putenv("DB_NAME={$name}");
        putenv("DB_USER={$user}");
        putenv("DB_PASSWORD={$password}");

        $this->pdo = $pdo;
        $this->removeTestCards();

        // The real XEMB cards would mix into these tests' pools, so XEMB is emptied while a test runs
        // (the stand-in cards stand in for it) and its real rows are put back in tearDown().
        $this->realCustomSetRows = $pdo->query("SELECT cs.* FROM card_sets cs JOIN sets s ON s.id = cs.set_id WHERE s.code = 'XEMB'")->fetchAll();
        $pdo->exec("DELETE cs FROM card_sets cs JOIN sets s ON s.id = cs.set_id WHERE s.code = 'XEMB'");

        $registry = DefaultEffectRegistry::build();
        $this->games = new GameService(
            new BoardStateRepository($registry),
            new MoodPlayService($registry),
            new RoundScorer(),
            new UserDecklistService(new UserDecklistRepository(), new FriendshipService(new UserRepository(), new FriendshipRepository())),
            new ReplayStateBuilder($registry),
            spawnAutomatedTurnRecheckProcesses: false,
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            $this->removeTestCards();
            $insert = $this->pdo->prepare('INSERT INTO card_sets (card_id, set_id, collector_number) VALUES (:card_id, :set_id, :collector_number)');
            foreach ($this->realCustomSetRows as $row) {
                $insert->execute(['card_id' => $row['card_id'], 'set_id' => $row['set_id'], 'collector_number' => $row['collector_number']]);
            }
            $this->realCustomSetRows = [];
        }
    }

    private function removeTestCards(): void
    {
        $this->pdo->exec('DELETE FROM game_cards WHERE card_id >= ' . self::FIRST_TEST_CARD_ID);
        $this->pdo->exec('DELETE FROM card_sets WHERE card_id >= ' . self::FIRST_TEST_CARD_ID);
        $this->pdo->exec('DELETE FROM cards WHERE id >= ' . self::FIRST_TEST_CARD_ID);
    }

    /** A name with no digits ("Test Custom CM") -- the decklist parser reads a trailing number as a collector number. */
    private static function testCardName(int $cardId): string
    {
        $n = $cardId - self::FIRST_TEST_CARD_ID;
        $letters = '';
        do {
            $letters = chr(65 + $n % 26) . $letters;
            $n = intdiv($n, 26) - 1;
        } while ($n >= 0);

        return "Test Custom {$letters}";
    }

    private function insertUser(string $username, bool $allowCustomContent): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO users (username, email, password_hash, email_verified_at, allow_custom_content) VALUES (:username, :email, 'hash', NOW(), :allow)"
        );
        $stmt->execute(['username' => $username, 'email' => "{$username}@example.com", 'allow' => $allowCustomContent ? 1 : 0]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Gives XEMB a stand-in card pool: $perRarity cards of each rarity (spread over the five colors),
     * all only in XEMB. Returns the new card ids, ascending.
     *
     * @param array<string, int> $perRarity
     * @return int[]
     */
    private function insertCustomSetCards(array $perRarity): array
    {
        $setId = (int) $this->pdo->query("SELECT id FROM sets WHERE code = 'XEMB'")->fetchColumn();
        $colors = ['white', 'blue', 'black', 'red', 'green'];
        $cardIds = [];
        $nextId = self::FIRST_TEST_CARD_ID;
        $collector = 1;
        foreach ($perRarity as $rarity => $count) {
            for ($i = 0; $i < $count; $i++) {
                $this->pdo->prepare(
                    "INSERT INTO cards (id, name, effect_key, color, rarity, base_value, rules_text, art_set_code)
                     VALUES (:id, :name, :effect_key, :color, :rarity, 3, 'Test card.', 'XEMB')"
                )->execute([
                    'id' => $nextId,
                    'name' => self::testCardName($nextId),
                    'effect_key' => "test_custom_{$nextId}",
                    'color' => $colors[$i % 5],
                    'rarity' => $rarity,
                ]);
                $this->pdo->prepare('INSERT INTO card_sets (card_id, set_id, collector_number) VALUES (:card_id, :set_id, :collector)')
                    ->execute(['card_id' => $nextId, 'set_id' => $setId, 'collector' => $collector++]);
                $cardIds[] = $nextId++;
            }
        }

        return $cardIds;
    }

    /** A full-size stand-in custom set -- enough of every rarity for a Structure deck and a Weekly Sealed Pool. @return int[] */
    private function insertFullCustomSet(): array
    {
        return $this->insertCustomSetCards(['common' => 30, 'uncommon' => 20, 'rare' => 12, 'mythic' => 8]);
    }

    /** @return int[] card ids dealt into $gameId's deck/hands */
    private function dealtCardIds(int $gameId): array
    {
        $stmt = $this->pdo->prepare('SELECT card_id FROM game_cards WHERE game_id = :game_id');
        $stmt->execute(['game_id' => $gameId]);

        return array_map(intval(...), $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public function testSetListHidesCustomSetsFromPlayersWhoHaveNotOptedIn(): void
    {
        $without = array_column(CardCatalog::sets(false), 'code');
        self::assertSame(['MSW'], $without);

        $with = CardCatalog::sets(true);
        self::assertSame(['MSW', 'XEMB'], array_column($with, 'code'));
        self::assertFalse($with[0]['is_custom']);
        self::assertTrue($with[1]['is_custom']);
        self::assertSame(0, $with[1]['card_count']);

        // What the New Game dialog is offered leaves out a set with no cards yet, until it has some.
        self::assertSame(['MSW'], array_column(CardCatalog::sets(true, nonEmptyOnly: true), 'code'));
        $this->insertCustomSetCards(['common' => 1]);
        self::assertSame(['MSW', 'XEMB'], array_column(CardCatalog::sets(true, nonEmptyOnly: true), 'code'));
    }

    public function testNormalizeSetCodes(): void
    {
        self::assertSame(['MSW'], CardCatalog::normalizeSetCodes(null, false));
        self::assertSame(['MSW'], CardCatalog::normalizeSetCodes([], true));
        self::assertSame(['MSW', 'XEMB'], CardCatalog::normalizeSetCodes(['msw', 'XEMB', 'xemb'], true));

        // A custom set the player cannot see reads as unknown, and so does a made-up one.
        try {
            CardCatalog::normalizeSetCodes(['XEMB'], false);
            self::fail('a custom set needs the opt-in');
        } catch (GameStateException $e) {
            self::assertStringContainsString('Unknown card set', $e->getMessage());
        }
        $this->expectException(GameStateException::class);
        CardCatalog::normalizeSetCodes(['NOPE'], true);
    }

    public function testStoredSetCodesRoundTrip(): void
    {
        self::assertNull(CardCatalog::encodeSetCodes(['MSW']));
        self::assertSame('MSW,XEMB', CardCatalog::encodeSetCodes(['MSW', 'XEMB']));
        self::assertSame(['MSW'], CardCatalog::setCodesOfGame(['card_set_codes' => null]));
        self::assertSame(['MSW', 'XEMB'], CardCatalog::setCodesOfGame(['card_set_codes' => 'MSW,XEMB']));
    }

    public function testCardsOnlyInACustomSetAreHiddenButAReprintIsNot(): void
    {
        [$customOnly, $reprint] = $this->insertCustomSetCards(['common' => 2]);
        // Make the second one a reprint of an original card by also putting it into MSW.
        $this->pdo->prepare('INSERT INTO card_sets (card_id, set_id, collector_number) VALUES (:card_id, 1, 900)')->execute(['card_id' => $reprint]);

        self::assertContains($customOnly, CardCatalog::customOnlyCardIds());
        self::assertNotContains($reprint, CardCatalog::customOnlyCardIds());

        self::assertArrayHasKey($customOnly, CardCatalog::load()['rowsById']);
        self::assertArrayNotHasKey($customOnly, CardCatalog::load(false)['rowsById']);
        self::assertArrayHasKey($reprint, CardCatalog::load(false)['rowsById']);
        self::assertArrayNotHasKey(mb_strtolower(self::testCardName($customOnly)), CardCatalog::load(false)['idsByName']);
        self::assertSame($customOnly, CardCatalog::load()['idsByName'][mb_strtolower(self::testCardName($customOnly))]);

        // A card in both sets shows up once when both are picked.
        self::assertSame(1, count(array_keys(CardCatalog::cardIdsInSets(['MSW', 'XEMB']), $reprint, true)));
        self::assertContains($reprint, CardCatalog::cardIdsInSets(['MSW']));
        self::assertNotContains($customOnly, CardCatalog::cardIdsInSets(['MSW']));
    }

    public function testACreatorWhoHasNotOptedInCannotPickACustomSet(): void
    {
        $alice = $this->insertUser('alice', false);
        $bob = $this->insertUser('bob', false);

        $this->expectException(GameStateException::class);
        $this->expectExceptionMessage('Unknown card set');
        $this->games->createGame($alice, [$alice, $bob], cardSetCodes: ['XEMB']);
    }

    public function testEverySeatedPlayerMustHaveOptedInForACustomSet(): void
    {
        $this->insertFullCustomSet();
        $alice = $this->insertUser('alice', true);
        $bob = $this->insertUser('bob', false);

        try {
            $this->games->createGame($alice, [$alice, $bob], cardSetCodes: ['MSW', 'XEMB']);
            self::fail('a seated player without the opt-in should block a custom set');
        } catch (GameStateException $e) {
            self::assertStringContainsString('custom card set', $e->getMessage());
            self::assertStringContainsString('bob', $e->getMessage());
        }

        $this->pdo->exec("UPDATE users SET allow_custom_content = 1 WHERE username = 'bob'");
        $gameId = $this->games->createGame($alice, [$alice, $bob], cardSetCodes: ['MSW', 'XEMB']);
        self::assertSame('MSW,XEMB', $this->pdo->query("SELECT card_set_codes FROM games WHERE id = {$gameId}")->fetchColumn());
    }

    public function testAGameThatNeverChoosesBuildsItsPoolsFromTheOriginalSetOnly(): void
    {
        $customIds = $this->insertFullCustomSet();
        $alice = $this->insertUser('alice', true);
        $bob = $this->insertUser('bob', true);

        $gameId = $this->games->createGame($alice, [$alice, $bob]);
        self::assertNull($this->pdo->query("SELECT card_set_codes FROM games WHERE id = {$gameId}")->fetchColumn());
        $this->games->startGame($gameId);

        self::assertSame([], array_values(array_intersect($this->dealtCardIds($gameId), $customIds)));
        self::assertNotSame([], $this->dealtCardIds($gameId));
    }

    public function testAStructureDeckCanBeBuiltFromTheCustomSetAlone(): void
    {
        $customIds = $this->insertFullCustomSet();
        $alice = $this->insertUser('alice', true);
        $bob = $this->insertUser('bob', true);

        $gameId = $this->games->createGame($alice, [$alice, $bob], cardSetCodes: ['XEMB']);
        $this->games->startGame($gameId);

        $dealt = $this->dealtCardIds($gameId);
        self::assertCount(45, $dealt);
        self::assertSame([], array_values(array_diff($dealt, $customIds)), 'every dealt card is from the custom set');
    }

    public function testAStructureDeckDoesNotCareAboutColorSoItCanHoldColorlessCards(): void
    {
        // Exactly the 45 cards a Structure deck needs, one of them colorless: all 45 are dealt, so it must be in there.
        $customIds = $this->insertCustomSetCards(['common' => 23, 'uncommon' => 14, 'rare' => 6, 'mythic' => 2]);
        $this->pdo->exec("UPDATE cards SET color = 'colorless' WHERE id = {$customIds[0]}");
        $alice = $this->insertUser('alice', true);
        $bob = $this->insertUser('bob', true);

        $gameId = $this->games->createGame($alice, [$alice, $bob], cardSetCodes: ['XEMB']);
        $this->games->startGame($gameId);

        self::assertContains($customIds[0], $this->dealtCardIds($gameId));
    }

    public function testPickingBothSetsDrawsFromBoth(): void
    {
        $customIds = $this->insertFullCustomSet();
        $originalIds = CardCatalog::cardIdsInSets(['MSW']);
        $alice = $this->insertUser('alice', true);
        $bob = $this->insertUser('bob', true);

        $sawCustom = false;
        $sawOriginal = false;
        // A 45-card draw from 133 + 70 cards is overwhelmingly likely to mix them; try a few games to be safe.
        for ($i = 0; $i < 6 && !($sawCustom && $sawOriginal); $i++) {
            $gameId = $this->games->createGame($alice, [$alice, $bob], cardSetCodes: ['MSW', 'XEMB']);
            $this->games->startGame($gameId);
            $dealt = $this->dealtCardIds($gameId);
            $sawCustom = $sawCustom || array_intersect($dealt, $customIds) !== [];
            $sawOriginal = $sawOriginal || array_intersect($dealt, $originalIds) !== [];
        }

        self::assertTrue($sawCustom && $sawOriginal);
    }

    public function testASetWithTooFewCardsIsAClearErrorNotACrash(): void
    {
        $this->insertCustomSetCards(['common' => 3]);
        $alice = $this->insertUser('alice', true);
        $bob = $this->insertUser('bob', true);

        $gameId = $this->games->createGame($alice, [$alice, $bob], cardSetCodes: ['XEMB']);

        $this->expectException(GameStateException::class);
        $this->expectExceptionMessage("don't have enough cards");
        $this->games->startGame($gameId);
    }

    public function testQuickDraftOneOfEachUsesOnlyTheChosenSets(): void
    {
        $customIds = $this->insertFullCustomSet();
        $alice = $this->insertUser('alice', true);
        $bob = $this->insertUser('bob', true);

        $gameId = $this->games->createGame($alice, [$alice, $bob], format: 'draft', deckType: 'quick_draft', quickDraftPoolSource: 'one_of_each', cardSetCodes: ['XEMB']);

        $pool = json_decode((string) $this->pdo->query(
            "SELECT dm.pool_card_ids FROM draft_matches dm JOIN games g ON g.draft_match_id = dm.id WHERE g.id = {$gameId}"
        )->fetchColumn(), true);
        self::assertSame([], array_values(array_diff(array_map('intval', $pool), $customIds)));
        self::assertNotSame([], $pool);
    }

    public function testDailyAndWeeklySealedPoolsNeverUseACustomSet(): void
    {
        $customIds = $this->insertFullCustomSet();
        $alice = $this->insertUser('alice', true);
        $bob = $this->insertUser('bob', true);

        // Even when a custom set is asked for outright, the periodic pool stays original-set only.
        $gameId = $this->games->createGame($alice, [$alice, $bob], format: 'draft', deckType: 'weekly_sealed_pool', cardSetCodes: ['XEMB']);
        self::assertNull($this->pdo->query("SELECT card_set_codes FROM games WHERE id = {$gameId}")->fetchColumn());

        $pool = json_decode((string) $this->pdo->query('SELECT pool_card_ids FROM periodic_sealed_pools ORDER BY id DESC LIMIT 1')->fetchColumn(), true);
        self::assertCount(50, $pool);
        self::assertSame([], array_values(array_intersect(array_map('intval', $pool), $customIds)));
    }

    public function testCustomDecklistsNamingCustomCardsNeedEverySeatedPlayerToOptIn(): void
    {
        $customIds = $this->insertFullCustomSet();
        $alice = $this->insertUser('alice', true);
        $bob = $this->insertUser('bob', false);
        $deckText = implode("\n", array_map(static fn (int $id): string => '1 ' . self::testCardName($id), array_slice($customIds, 0, 45)));

        $this->expectException(GameStateException::class);
        $this->expectExceptionMessage('bob');
        $this->games->createGame($alice, [$alice, $bob], deckType: 'custom', decklistText: $deckText);
    }
}
