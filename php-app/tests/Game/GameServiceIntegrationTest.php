<?php

declare(strict_types=1);

namespace MoodSwings\Tests\Game;

use MoodSwings\Game\BoardStateRepository;
use MoodSwings\Game\Exceptions\GameStateException;
use MoodSwings\Game\GameService;
use MoodSwings\Rules\DefaultEffectRegistry;
use MoodSwings\Rules\Exceptions\IllegalPlayException;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\MoodPlayService;
use MoodSwings\Rules\RoundScorer;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

final class GameServiceIntegrationTest extends TestCase
{
    private PDO $pdo;
    private GameService $games;

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
        $pdo->exec('TRUNCATE TABLE game_events');
        $pdo->exec('TRUNCATE TABLE game_pending_decisions');
        $pdo->exec('TRUNCATE TABLE game_pending_decision_batches');
        $pdo->exec('TRUNCATE TABLE game_round_scores');
        $pdo->exec('TRUNCATE TABLE game_cards');
        $pdo->exec('TRUNCATE TABLE game_rounds');
        $pdo->exec('TRUNCATE TABLE game_players');
        $pdo->exec('TRUNCATE TABLE games');
        $pdo->exec('TRUNCATE TABLE users');
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

        putenv("DB_HOST={$host}");
        putenv("DB_PORT={$port}");
        putenv("DB_NAME={$name}");
        putenv("DB_USER={$user}");
        putenv("DB_PASSWORD={$password}");

        $this->pdo = $pdo;

        $registry = DefaultEffectRegistry::build();
        $this->games = new GameService(
            new BoardStateRepository($registry),
            new MoodPlayService($registry),
            new RoundScorer(),
        );
    }

    private function insertUser(string $username): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO users (username, email, password_hash, email_verified_at)
             VALUES (:username, :email, 'hash', NOW())"
        );
        $stmt->execute(['username' => $username, 'email' => "{$username}@example.com"]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @return int game_players.id */
    private function insertGamePlayer(int $gameId, int $userId, int $seatOrder): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO game_players (game_id, user_id, seat_order) VALUES (:game_id, :user_id, :seat_order)'
        );
        $stmt->execute(['game_id' => $gameId, 'user_id' => $userId, 'seat_order' => $seatOrder]);

        return (int) $this->pdo->lastInsertId();
    }

    private function insertGameCard(
        int $gameId,
        int $cardId,
        string $zone,
        ?int $owner = null,
        ?int $deckPosition = null,
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO game_cards (game_id, card_id, zone, owner_game_player_id, deck_position)
             VALUES (:game_id, :card_id, :zone, :owner, :deck_position)'
        );
        $stmt->execute([
            'game_id' => $gameId,
            'card_id' => $cardId,
            'zone' => $zone,
            'owner' => $owner,
            'deck_position' => $deckPosition,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function insertGameRound(
        int $gameId,
        int $roundNumber,
        int $firstPlayerId,
        int $currentTurnPlayerId,
        int $playsRemaining,
        ?int $hurtFeelingsPlayerId = null,
    ): int {
        $stmt = $this->pdo->prepare(
            "INSERT INTO game_rounds (game_id, round_number, first_game_player_id, hurt_feelings_game_player_id, current_turn_game_player_id, plays_remaining, status)
             VALUES (:game_id, :round_number, :first_player, :hurt_feelings, :current_turn, :plays_remaining, 'in_progress')"
        );
        $stmt->execute([
            'game_id' => $gameId,
            'round_number' => $roundNumber,
            'first_player' => $firstPlayerId,
            'hurt_feelings' => $hurtFeelingsPlayerId,
            'current_turn' => $currentTurnPlayerId,
            'plays_remaining' => $playsRemaining,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function fetchRound(int $gameId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM game_rounds WHERE game_id = :game_id AND status = 'in_progress' ORDER BY round_number DESC LIMIT 1"
        );
        $stmt->execute(['game_id' => $gameId]);

        return $stmt->fetch();
    }

    private function fetchGame(int $gameId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM games WHERE id = :game_id');
        $stmt->execute(['game_id' => $gameId]);

        return $stmt->fetch();
    }

    public function testCreateGameAndStartGameDealsCardsAndBeginsFirstRound(): void
    {
        $creator = $this->insertUser('alice');
        $bob = $this->insertUser('bob');
        $carol = $this->insertUser('carol');

        // deckType is pinned to 'one_of_each' here so this test's own
        // deck-count math (133 total, one of every printed card) stays
        // meaningful regardless of which deck_type createGame() defaults
        // to -- see testCreateGameDealsAStructureDeckByDefault() for the
        // 'structure' deck_type's own (smaller, rarity-weighted) math.
        $gameId = $this->games->createGame($creator, [$creator, $bob, $carol], deckType: 'one_of_each');
        $this->games->startGame($gameId);

        $stmt = $this->pdo->prepare('SELECT id FROM game_players WHERE game_id = :game_id ORDER BY seat_order ASC');
        $stmt->execute(['game_id' => $gameId]);
        $playerIds = array_map(intval(...), $stmt->fetchAll(PDO::FETCH_COLUMN));
        self::assertCount(3, $playerIds);

        foreach ($playerIds as $playerId) {
            $handStmt = $this->pdo->prepare(
                "SELECT COUNT(*) FROM game_cards WHERE game_id = :game_id AND zone = 'hand' AND owner_game_player_id = :player_id"
            );
            $handStmt->execute(['game_id' => $gameId, 'player_id' => $playerId]);
            self::assertSame(5, (int) $handStmt->fetchColumn());
        }

        $deckStmt = $this->pdo->prepare("SELECT deck_position FROM game_cards WHERE game_id = :game_id AND zone = 'deck' ORDER BY deck_position ASC");
        $deckStmt->execute(['game_id' => $gameId]);
        $positions = array_map(intval(...), $deckStmt->fetchAll(PDO::FETCH_COLUMN));
        self::assertSame(133 - 5 * 3, count($positions));
        self::assertSame(range(0, count($positions) - 1), $positions);

        $round = $this->fetchRound($gameId);
        self::assertSame(1, (int) $round['round_number']);
        self::assertContains((int) $round['first_game_player_id'], $playerIds);
        self::assertSame((int) $round['first_game_player_id'], (int) $round['current_turn_game_player_id']);
        self::assertSame(1, (int) $round['plays_remaining']);

        self::assertSame('in_progress', $this->fetchGame($gameId)['status']);
    }

    public function testCreateGameDealsAStructureDeckByDefault(): void
    {
        // 'structure' -- 23 common/14 uncommon/6 rare/2 mythic (45 total),
        // matching a new physical box's own printed rarity distribution --
        // is deck_type's default, unlike the full 133-card 'one_of_each'
        // pool every game used before this existed (see
        // testCreateGameAndStartGameDealsCardsAndBeginsFirstRound() above,
        // which pins deckType explicitly for that reason).
        $creator = $this->insertUser('deck-alice');
        $bob = $this->insertUser('deck-bob');

        $gameId = $this->games->createGame($creator, [$creator, $bob]);
        self::assertSame('structure', $this->fetchGame($gameId)['deck_type']);

        $this->games->startGame($gameId);

        $cardIdsStmt = $this->pdo->prepare("SELECT card_id FROM game_cards WHERE game_id = :game_id");
        $cardIdsStmt->execute(['game_id' => $gameId]);
        $cardIds = array_map(intval(...), $cardIdsStmt->fetchAll(PDO::FETCH_COLUMN));

        self::assertCount(45, $cardIds);
        self::assertCount(45, array_unique($cardIds), 'a structure deck must be singleton -- no repeated card ids');

        $placeholders = implode(',', array_fill(0, count($cardIds), '?'));
        $rarityStmt = $this->pdo->prepare("SELECT rarity, COUNT(*) AS n FROM cards WHERE id IN ({$placeholders}) GROUP BY rarity");
        $rarityStmt->execute($cardIds);
        $countsByRarity = array_column($rarityStmt->fetchAll(), 'n', 'rarity');

        self::assertSame([
            'common' => 23,
            'uncommon' => 14,
            'rare' => 6,
            'mythic' => 2,
        ], array_map(intval(...), $countsByRarity));
    }

    public function testCreateGameCanRequestThePowerDeckInstead(): void
    {
        $creator = $this->insertUser('power-alice');
        $bob = $this->insertUser('power-bob');

        $gameId = $this->games->createGame($creator, [$creator, $bob], deckType: 'power');
        self::assertSame('power', $this->fetchGame($gameId)['deck_type']);

        $this->games->startGame($gameId);

        $cardIdsStmt = $this->pdo->prepare("SELECT card_id FROM game_cards WHERE game_id = :game_id");
        $cardIdsStmt->execute(['game_id' => $gameId]);
        $cardIds = array_map(intval(...), $cardIdsStmt->fetchAll(PDO::FETCH_COLUMN));

        self::assertCount(15, $cardIds);
        self::assertCount(15, array_unique($cardIds), 'a power deck must be singleton -- no repeated card ids');

        $placeholders = implode(',', array_fill(0, count($cardIds), '?'));
        $rarityStmt = $this->pdo->prepare("SELECT rarity, COUNT(*) AS n FROM cards WHERE id IN ({$placeholders}) GROUP BY rarity");
        $rarityStmt->execute($cardIds);
        $countsByRarity = array_column($rarityStmt->fetchAll(), 'n', 'rarity');

        self::assertSame(1, (int) ($countsByRarity['mythic'] ?? 0), 'a power deck must have exactly one mythic');
        self::assertSame(14, array_sum($countsByRarity) - (int) ($countsByRarity['mythic'] ?? 0));
    }

    public function testCreateGameCanRequestJceddys75DeckInstead(): void
    {
        $creator = $this->insertUser('jceddy75-alice');
        $bob = $this->insertUser('jceddy75-bob');

        $gameId = $this->games->createGame($creator, [$creator, $bob], deckType: 'jceddys_75');
        self::assertSame('jceddys_75', $this->fetchGame($gameId)['deck_type']);

        $this->games->startGame($gameId);

        $cardIdsStmt = $this->pdo->prepare("SELECT card_id FROM game_cards WHERE game_id = :game_id");
        $cardIdsStmt->execute(['game_id' => $gameId]);
        $cardIds = array_map(intval(...), $cardIdsStmt->fetchAll(PDO::FETCH_COLUMN));

        self::assertCount(75, $cardIds);

        $uniqueCardIds = array_unique($cardIds);
        $placeholders = implode(',', array_fill(0, count($uniqueCardIds), '?'));
        $catalogStmt = $this->pdo->prepare("SELECT id, color, rarity FROM cards WHERE id IN ({$placeholders})");
        $catalogStmt->execute(array_values($uniqueCardIds));
        $catalogById = [];
        foreach ($catalogStmt->fetchAll() as $row) {
            $catalogById[(int) $row['id']] = $row;
        }

        // Every drawn id has to have actually resolved to a real catalog
        // row -- rules out a stray id sneaking in from the wrong
        // color/rarity pool. (The catalog itself only has one row per id,
        // so this checks against $uniqueCardIds, not the full 75-long
        // $cardIds -- which legitimately repeats ids within their cap.)
        self::assertCount(count($uniqueCardIds), $catalogById);

        $copiesById = array_count_values($cardIds);
        $countsByColorRarity = [];
        foreach ($cardIds as $cardId) {
            $row = $catalogById[$cardId];
            $key = $row['color'] . '/' . $row['rarity'];
            $countsByColorRarity[$key] = ($countsByColorRarity[$key] ?? 0) + 1;

            $maxCopies = match ($row['rarity']) {
                'mythic', 'rare' => 1,
                'uncommon' => 2,
                'common' => 3,
            };
            self::assertLessThanOrEqual(
                $maxCopies,
                $copiesById[$cardId],
                "card {$cardId} ({$row['rarity']}) appears {$copiesById[$cardId]} times, over its {$maxCopies}-copy cap",
            );
        }

        foreach (['white', 'blue', 'black', 'red', 'green'] as $color) {
            self::assertSame(1, $countsByColorRarity["{$color}/mythic"] ?? 0, "{$color} should have exactly 1 mythic");
            self::assertSame(2, $countsByColorRarity["{$color}/rare"] ?? 0, "{$color} should have exactly 2 rares");
            self::assertSame(4, $countsByColorRarity["{$color}/uncommon"] ?? 0, "{$color} should have exactly 4 uncommons");
            self::assertSame(8, $countsByColorRarity["{$color}/common"] ?? 0, "{$color} should have exactly 8 commons");
        }
    }

    public function testCreateGameCanRequestTheOneOfEachDeckInstead(): void
    {
        $creator = $this->insertUser('ooe-alice');
        $bob = $this->insertUser('ooe-bob');

        $gameId = $this->games->createGame($creator, [$creator, $bob], deckType: 'one_of_each');
        self::assertSame('one_of_each', $this->fetchGame($gameId)['deck_type']);

        $this->games->startGame($gameId);

        $cardIdsStmt = $this->pdo->prepare("SELECT card_id FROM game_cards WHERE game_id = :game_id");
        $cardIdsStmt->execute(['game_id' => $gameId]);
        $cardIds = array_map(intval(...), $cardIdsStmt->fetchAll(PDO::FETCH_COLUMN));

        self::assertCount(133, $cardIds);
        self::assertSame(range(1, 133), (function (array $ids) {
            sort($ids);
            return $ids;
        })($cardIds));
    }

    public function testCreateGameCanRequestACustomDecklistInstead(): void
    {
        $creator = $this->insertUser('custom-alice');
        $bob = $this->insertUser('custom-bob');

        $decklistText = <<<'DECK'
            About
            Name My Awesome Deck

            1 Altruism
            1 Benevolence
            1 Charity
            1 Chivalry
            1 Complacency
            1 Conviction
            1 Courage
            1 Dignity
            1 Discipline
            1 Disillusionment
            1 Encouragement
            1 Faith
            1 Friendliness
            1 Guilt
            1 Honor
            DECK;

        $gameId = $this->games->createGame($creator, [$creator, $bob], deckType: 'custom', decklistText: $decklistText);
        $game = $this->fetchGame($gameId);
        self::assertSame('custom', $game['deck_type']);
        self::assertSame('My Awesome Deck', $game['custom_deck_name']);
        self::assertSame(range(1, 15), array_map(intval(...), json_decode($game['custom_deck_card_ids'], true)));

        $this->games->startGame($gameId);

        $cardIdsStmt = $this->pdo->prepare('SELECT card_id FROM game_cards WHERE game_id = :game_id');
        $cardIdsStmt->execute(['game_id' => $gameId]);
        $cardIds = array_map(intval(...), $cardIdsStmt->fetchAll(PDO::FETCH_COLUMN));

        sort($cardIds);
        self::assertSame(range(1, 15), $cardIds);
    }

    public function testCreateGameRejectsACustomDecklistForADuelGame(): void
    {
        $u1 = $this->insertUser('customduel1');
        $u2 = $this->insertUser('customduel2');

        $this->expectException(GameStateException::class);
        $this->expectExceptionMessage('not supported for duel games');

        $this->games->createGame($u1, [$u1, $u2], format: 'duel', deckType: 'custom', decklistText: '1 Altruism');
    }

    public function testCreateGameRejectsACustomDecklistBelowTheMinimumCardCountForThePlayerCount(): void
    {
        $creator = $this->insertUser('custom-few-alice');
        $bob = $this->insertUser('custom-few-bob');
        $carol = $this->insertUser('custom-few-carol');

        // 3 players need at least 30 cards (15 * (playerCount - 1)) -- this
        // decklist only has 15.
        $decklistText = "1 Altruism\n1 Benevolence\n1 Charity\n1 Chivalry\n1 Complacency\n"
            . "1 Conviction\n1 Courage\n1 Dignity\n1 Discipline\n1 Disillusionment\n"
            . "1 Encouragement\n1 Faith\n1 Friendliness\n1 Guilt\n1 Honor";

        $this->expectException(GameStateException::class);
        $this->expectExceptionMessage('at least 30 are required for 3 players');

        $this->games->createGame($creator, [$creator, $bob, $carol], deckType: 'custom', decklistText: $decklistText);
    }

    public function testCreateGameRejectsACustomDecklistWithAnUnrecognizedCard(): void
    {
        $creator = $this->insertUser('custom-bad-alice');
        $bob = $this->insertUser('custom-bad-bob');

        $this->expectException(GameStateException::class);
        $this->expectExceptionMessage('Unrecognized card');

        $this->games->createGame($creator, [$creator, $bob], deckType: 'custom', decklistText: 'Not A Real Card Name');
    }

    public function testCreateGameRejectsACustomDeckTypeWithNoDecklistText(): void
    {
        $creator = $this->insertUser('custom-empty-alice');
        $bob = $this->insertUser('custom-empty-bob');

        $this->expectException(GameStateException::class);

        $this->games->createGame($creator, [$creator, $bob], deckType: 'custom');
    }

    public function testGetStateExposesTheCustomDeckName(): void
    {
        $creator = $this->insertUser('custom-state-alice');
        $bob = $this->insertUser('custom-state-bob');

        $decklistText = "About\nName State Test Deck\n\n1 Altruism\n1 Benevolence\n1 Charity\n1 Chivalry\n1 Complacency\n"
            . "1 Conviction\n1 Courage\n1 Dignity\n1 Discipline\n1 Disillusionment\n"
            . "1 Encouragement\n1 Faith\n1 Friendliness\n1 Guilt\n1 Honor";

        $gameId = $this->games->createGame($creator, [$creator, $bob], deckType: 'custom', decklistText: $decklistText);

        $state = $this->games->getState($gameId, $creator);
        self::assertSame('State Test Deck', $state['game']['custom_deck_name']);
    }

    public function testGetStateHasANullCustomDeckNameWhenTheDecklistDidntSpecifyOne(): void
    {
        $creator = $this->insertUser('custom-state-noname-alice');
        $bob = $this->insertUser('custom-state-noname-bob');

        $decklistText = "1 Altruism\n1 Benevolence\n1 Charity\n1 Chivalry\n1 Complacency\n"
            . "1 Conviction\n1 Courage\n1 Dignity\n1 Discipline\n1 Disillusionment\n"
            . "1 Encouragement\n1 Faith\n1 Friendliness\n1 Guilt\n1 Honor";

        $gameId = $this->games->createGame($creator, [$creator, $bob], deckType: 'custom', decklistText: $decklistText);

        $state = $this->games->getState($gameId, $creator);
        self::assertNull($state['game']['custom_deck_name']);
    }

    public function testCreateGameRejectsCustomDuelForNonDuelFormat(): void
    {
        $creator = $this->insertUser('customduel-nonduel-alice');
        $bob = $this->insertUser('customduel-nonduel-bob');

        $this->expectException(GameStateException::class);
        $this->expectExceptionMessage('only supported for duel games');

        $this->games->createGame(
            $creator,
            [$creator, $bob],
            deckType: 'custom_duel',
            duelDeckRules: ['preset' => 'user_defined', 'min_cards' => 7],
        );
    }

    public function testCreateGameRejectsCustomDuelWithoutRules(): void
    {
        $u1 = $this->insertUser('customduel-norules1');
        $u2 = $this->insertUser('customduel-norules2');

        $this->expectException(GameStateException::class);
        $this->expectExceptionMessage('Duel deck-building rules are required');

        $this->games->createGame($u1, [$u1, $u2], format: 'duel', deckType: 'custom_duel');
    }

    public function testCreateGameRejectsAUserDefinedMinCardsBelowSeven(): void
    {
        $u1 = $this->insertUser('customduel-toofew1');
        $u2 = $this->insertUser('customduel-toofew2');

        $this->expectException(GameStateException::class);
        $this->expectExceptionMessage('cannot be lower than 7');

        $this->games->createGame(
            $u1,
            [$u1, $u2],
            format: 'duel',
            deckType: 'custom_duel',
            duelDeckRules: ['preset' => 'user_defined', 'min_cards' => 5],
        );
    }

    public function testCreateGameLocksCustomDuelRulesToTheStructurePreset(): void
    {
        $u1 = $this->insertUser('customduel-structure1');
        $u2 = $this->insertUser('customduel-structure2');

        $gameId = $this->games->createGame(
            $u1,
            [$u1, $u2],
            format: 'duel',
            deckType: 'custom_duel',
            duelDeckRules: ['preset' => 'structure'],
        );

        $game = $this->fetchGame($gameId);
        self::assertSame('structure', $game['custom_duel_rules_preset']);
        self::assertSame(45, (int) $game['custom_duel_min_cards']);
        self::assertSame(
            ['common' => 23, 'uncommon' => 14, 'rare' => 6, 'mythic' => 2],
            json_decode($game['custom_duel_rarity_limits'], true),
        );
        self::assertSame(
            ['common' => 1, 'uncommon' => 1, 'rare' => 1, 'mythic' => 1],
            json_decode($game['custom_duel_duplicate_limits'], true),
        );
    }

    public function testCreateGameLocksCustomDuelRulesToTheJceddys75PresetIgnoringAnyUserSuppliedValues(): void
    {
        $u1 = $this->insertUser('customduel-jceddy1');
        $u2 = $this->insertUser('customduel-jceddy2');

        // A preset name locks the values regardless of whatever the
        // client also sent alongside it.
        $gameId = $this->games->createGame(
            $u1,
            [$u1, $u2],
            format: 'duel',
            deckType: 'custom_duel',
            duelDeckRules: ['preset' => 'jceddys_75', 'min_cards' => 999, 'rarity_limits' => ['common' => 1]],
        );

        $game = $this->fetchGame($gameId);
        self::assertSame(75, (int) $game['custom_duel_min_cards']);
        self::assertSame(
            ['mythic' => 5, 'rare' => 10, 'uncommon' => 20, 'common' => 40],
            json_decode($game['custom_duel_rarity_limits'], true),
        );
        // The jceddys_75 preset locks even-color-distribution on for all
        // four rarities, matching the real generator's own "N per color,
        // for every color" guarantee.
        self::assertSame(
            ['common', 'uncommon', 'rare', 'mythic'],
            json_decode($game['custom_duel_even_color_distribution_rarities'], true),
        );
    }

    public function testSubmitCustomDuelDeckRejectsTooFewCards(): void
    {
        ['gameId' => $gameId, 'p1' => $p1] = $this->buildCustomDuelFixture(minCards: 10);

        $this->expectException(GameStateException::class);
        $this->expectExceptionMessage('has only 2 card(s), but at least 10 are required');

        $this->games->submitCustomDuelDeck($gameId, $p1, "1 Charity\n1 Dignity");
    }

    public function testSubmitCustomDuelDeckRejectsExceedingARarityLimit(): void
    {
        ['gameId' => $gameId, 'p1' => $p1] = $this->buildCustomDuelFixture(
            minCards: 7,
            rarityLimits: ['common' => 2],
        );

        $this->expectException(GameStateException::class);
        $this->expectExceptionMessage('has 3 common card(s), but at most 2 common card(s) are allowed');

        // Charity/Chivalry/Complacency are common (3); Benevolence/Conviction/Encouragement/Faith are uncommon.
        $this->games->submitCustomDuelDeck($gameId, $p1, "1 Charity\n1 Chivalry\n1 Complacency\n1 Benevolence\n1 Conviction\n1 Encouragement\n1 Faith");
    }

    public function testSubmitCustomDuelDeckRejectsExceedingADuplicateLimit(): void
    {
        ['gameId' => $gameId, 'p1' => $p1] = $this->buildCustomDuelFixture(
            minCards: 7,
            duplicateLimits: ['common' => 1],
        );

        $this->expectException(GameStateException::class);
        $this->expectExceptionMessage('has 2 copies of "Charity" (common), but at most 1 copy of any common card are allowed');

        $this->games->submitCustomDuelDeck($gameId, $p1, "2 Charity\n1 Dignity\n1 Courage\n1 Complacency\n1 Chivalry\n1 Benevolence");
    }

    public function testSubmitCustomDuelDeckAcceptsAnEvenColorDistribution(): void
    {
        ['gameId' => $gameId, 'p1' => $p1] = $this->buildCustomDuelFixture(
            minCards: 7,
            evenColorDistributionRarities: ['uncommon'],
        );

        // One uncommon of each color: Benevolence (white), Confusion
        // (blue), Angst (black), Anger (red), Eagerness (green) -- plus 2
        // common fillers to clear the 7-card floor (evenColorDistribution
        // only applies to 'uncommon' here, so their colors don't matter).
        $this->games->submitCustomDuelDeck($gameId, $p1, "1 Benevolence\n1 Confusion\n1 Angst\n1 Anger\n1 Eagerness\n1 Charity\n1 Dignity");

        $p1Row = $this->pdo->prepare('SELECT custom_deck_card_ids FROM game_players WHERE id = :id');
        $p1Row->execute(['id' => $p1]);
        self::assertNotNull($p1Row->fetchColumn());
    }

    public function testSubmitCustomDuelDeckRejectsAnUnevenColorDistribution(): void
    {
        ['gameId' => $gameId, 'p1' => $p1] = $this->buildCustomDuelFixture(
            minCards: 7,
            evenColorDistributionRarities: ['uncommon'],
        );

        $this->expectException(GameStateException::class);
        $this->expectExceptionMessage('must have exactly 1 white uncommon card(s) for an even distribution across colors (has 2)');

        // 2 white uncommons (Benevolence, Conviction) instead of 1 white + 1 green -- still 5 uncommons total (divisible by 5).
        $this->games->submitCustomDuelDeck($gameId, $p1, "1 Benevolence\n1 Conviction\n1 Angst\n1 Anger\n1 Confusion\n1 Charity\n1 Dignity");
    }

    public function testSubmitCustomDuelDeckRejectsATotalThatCannotSplitEvenlyAcrossColors(): void
    {
        ['gameId' => $gameId, 'p1' => $p1] = $this->buildCustomDuelFixture(
            minCards: 7,
            evenColorDistributionRarities: ['uncommon'],
        );

        $this->expectException(GameStateException::class);
        $this->expectExceptionMessage("has 4 uncommon card(s), which can't be split evenly across the 5 colors");

        // Only 4 uncommons (no green) -- not divisible by 5 -- plus 3 common fillers.
        $this->games->submitCustomDuelDeck($gameId, $p1, "1 Benevolence\n1 Confusion\n1 Angst\n1 Anger\n1 Charity\n1 Dignity\n1 Courage");
    }

    public function testSubmitCustomDuelDeckRejectsAPlayerNotSeatedInTheGame(): void
    {
        ['gameId' => $gameId] = $this->buildCustomDuelFixture(minCards: 7);
        $outsider = $this->insertUser('customduel-outsider');

        $this->expectException(GameStateException::class);
        $this->expectExceptionMessage('is not seated');

        $this->games->submitCustomDuelDeck($gameId, $outsider, '1 Charity');
    }

    public function testStartGameRejectsACustomDuelGameUntilBothPlayersHaveSubmittedADeck(): void
    {
        ['gameId' => $gameId, 'p1' => $p1] = $this->buildCustomDuelFixture(minCards: 7);

        $this->games->submitCustomDuelDeck($gameId, $p1, "1 Charity\n1 Chivalry\n1 Complacency\n1 Courage\n1 Dignity\n1 Discipline\n1 Loyalty");

        $this->expectException(GameStateException::class);
        $this->expectExceptionMessage('cannot start until every player has submitted a decklist');

        $this->games->startGame($gameId);
    }

    public function testStartGameDealsEachCustomDuelPlayerTheirOwnSubmittedDeck(): void
    {
        ['gameId' => $gameId, 'p1' => $p1, 'p2' => $p2] = $this->buildCustomDuelFixture(minCards: 7);

        $this->games->submitCustomDuelDeck(
            $gameId,
            $p1,
            "About\nName Alice's Deck\n\n1 Charity\n1 Chivalry\n1 Complacency\n1 Courage\n1 Dignity\n1 Discipline\n1 Loyalty",
        );
        $this->games->submitCustomDuelDeck(
            $gameId,
            $p2,
            "About\nName Bob's Deck\n\n1 Benevolence\n1 Conviction\n1 Encouragement\n1 Faith\n1 Friendliness\n1 Guilt\n1 Kindness",
        );

        $this->games->startGame($gameId);

        $p1CardsStmt = $this->pdo->prepare("SELECT card_id FROM game_cards WHERE game_id = :game_id AND owner_game_player_id = :owner");
        $p1CardsStmt->execute(['game_id' => $gameId, 'owner' => $p1]);
        $p1CardIds = array_map(intval(...), $p1CardsStmt->fetchAll(PDO::FETCH_COLUMN));
        sort($p1CardIds);
        // Charity=3, Chivalry=4, Complacency=5, Courage=7, Dignity=8, Discipline=9, Loyalty=18.
        self::assertSame([3, 4, 5, 7, 8, 9, 18], $p1CardIds);

        $p2CardsStmt = $this->pdo->prepare("SELECT card_id FROM game_cards WHERE game_id = :game_id AND owner_game_player_id = :owner");
        $p2CardsStmt->execute(['game_id' => $gameId, 'owner' => $p2]);
        $p2CardIds = array_map(intval(...), $p2CardsStmt->fetchAll(PDO::FETCH_COLUMN));
        sort($p2CardIds);
        // Benevolence=2, Conviction=6, Encouragement=11, Faith=12, Friendliness=13, Guilt=14, Kindness=17.
        self::assertSame([2, 6, 11, 12, 13, 14, 17], $p2CardIds);

        $game = $this->fetchGame($gameId);
        self::assertSame('in_progress', $game['status']);
    }

    public function testGetStateExposesDuelDeckRulesAndPerPlayerSubmissionStatus(): void
    {
        ['gameId' => $gameId, 'p1' => $p1, 'u1' => $u1, 'u2' => $u2] = $this->buildCustomDuelFixture(
            minCards: 7,
            rarityLimits: ['common' => 7],
        );

        $before = $this->games->getState($gameId, $u1);
        self::assertSame([
            'preset' => 'user_defined',
            'min_cards' => 7,
            'rarity_limits' => ['common' => 7],
            'duplicate_limits' => [],
            'even_color_distribution_rarities' => [],
        ], $before['game']['duel_deck_rules']);
        self::assertFalse($before['players'][0]['deck_submitted']);
        self::assertFalse($before['players'][1]['deck_submitted']);

        $this->games->submitCustomDuelDeck(
            $gameId,
            $p1,
            "About\nName Alice's Deck\n\n1 Charity\n1 Chivalry\n1 Complacency\n1 Courage\n1 Dignity\n1 Discipline\n1 Loyalty",
        );

        $after = $this->games->getState($gameId, $u1);
        $p1State = $after['players'][0]['game_player_id'] === $p1 ? $after['players'][0] : $after['players'][1];
        $p2State = $after['players'][0]['game_player_id'] === $p1 ? $after['players'][1] : $after['players'][0];
        self::assertTrue($p1State['deck_submitted']);
        self::assertSame("Alice's Deck", $p1State['custom_deck_name']);
        self::assertFalse($p2State['deck_submitted']);
    }

    /**
     * @return array{gameId: int, p1: int, p2: int, u1: int, u2: int}
     */
    private function buildCustomDuelFixture(
        int $minCards,
        array $rarityLimits = [],
        array $duplicateLimits = [],
        array $evenColorDistributionRarities = [],
    ): array {
        $u1 = $this->insertUser('customduel-' . uniqid('u1'));
        $u2 = $this->insertUser('customduel-' . uniqid('u2'));

        $gameId = $this->games->createGame(
            $u1,
            [$u1, $u2],
            format: 'duel',
            deckType: 'custom_duel',
            duelDeckRules: [
                'preset' => 'user_defined',
                'min_cards' => $minCards,
                'rarity_limits' => $rarityLimits,
                'duplicate_limits' => $duplicateLimits,
                'even_color_distribution_rarities' => $evenColorDistributionRarities,
            ],
        );

        $p1 = (int) $this->games->gamePlayerIdFor($gameId, $u1);
        $p2 = (int) $this->games->gamePlayerIdFor($gameId, $u2);

        return ['gameId' => $gameId, 'p1' => $p1, 'p2' => $p2, 'u1' => $u1, 'u2' => $u2];
    }

    public function testCreateGameRejectsADuelWithMoreThanTwoPlayers(): void
    {
        $u1 = $this->insertUser('dueltoomany1');
        $u2 = $this->insertUser('dueltoomany2');
        $u3 = $this->insertUser('dueltoomany3');

        $this->expectException(GameStateException::class);
        $this->games->createGame($u1, [$u1, $u2, $u3], format: 'duel');
    }

    public function testCreateGameRejectsADuelWithFewerThanTwoPlayers(): void
    {
        $u1 = $this->insertUser('dueltoofew1');

        $this->expectException(GameStateException::class);
        $this->games->createGame($u1, [$u1], format: 'duel');
    }

    public function testCreateGameAcceptsADuelWithExactlyTwoPlayers(): void
    {
        $u1 = $this->insertUser('dueltwo1');
        $u2 = $this->insertUser('dueltwo2');

        $gameId = $this->games->createGame($u1, [$u1, $u2], format: 'duel');

        self::assertSame('duel', $this->fetchGame($gameId)['format']);
    }

    public function testStartGameGivesEachDuelPlayerTheirOwnIndependentOneOfEachDeck(): void
    {
        $u1 = $this->insertUser('duelsplit1');
        $u2 = $this->insertUser('duelsplit2');

        $gameId = $this->games->createGame($u1, [$u1, $u2], format: 'duel', deckType: 'one_of_each');
        $this->games->startGame($gameId);

        $p1 = $this->games->gamePlayerIdFor($gameId, $u1);
        $p2 = $this->games->gamePlayerIdFor($gameId, $u2);

        $nullOwnerStmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM game_cards WHERE game_id = :game_id AND zone = 'deck' AND owner_game_player_id IS NULL"
        );
        $nullOwnerStmt->execute(['game_id' => $gameId]);
        self::assertSame(0, (int) $nullOwnerStmt->fetchColumn()); // no shared/ownerless deck rows in a duel

        $deckStmt = $this->pdo->prepare(
            "SELECT owner_game_player_id, COUNT(*) AS n FROM game_cards WHERE game_id = :game_id AND zone = 'deck' GROUP BY owner_game_player_id"
        );
        $deckStmt->execute(['game_id' => $gameId]);
        $counts = array_column($deckStmt->fetchAll(), 'n', 'owner_game_player_id');

        // Each player gets their OWN complete deck (133 total, 5 dealt to
        // hand), not a shared pool split between them.
        self::assertSame(133 - 5, (int) $counts[$p1]);
        self::assertSame(133 - 5, (int) $counts[$p2]);

        // 'one_of_each' means each player's own hand+deck is a full,
        // independent permutation of every catalog card, so the two
        // players' own sets of catalog card ids are identical (just
        // shuffled differently) rather than disjoint -- proving these
        // weren't split from one shared pool, which would have made the
        // same catalog card appearing in both sets structurally
        // impossible.
        $catalogIdsFor = function (int $gamePlayerId) use ($gameId): array {
            $stmt = $this->pdo->prepare(
                "SELECT card_id FROM game_cards WHERE game_id = :game_id AND owner_game_player_id = :owner AND zone IN ('hand', 'deck')"
            );
            $stmt->execute(['game_id' => $gameId, 'owner' => $gamePlayerId]);
            $ids = array_map(intval(...), $stmt->fetchAll(PDO::FETCH_COLUMN));
            sort($ids);

            return $ids;
        };
        $p1CatalogIds = $catalogIdsFor($p1);
        $p2CatalogIds = $catalogIdsFor($p2);

        self::assertCount(133, array_unique($p1CatalogIds));
        self::assertSame($p1CatalogIds, $p2CatalogIds);
    }

    public function testStartGameGivesEachDuelPlayerTheirOwnIndependentStructureDeck(): void
    {
        $u1 = $this->insertUser('duelstructure1');
        $u2 = $this->insertUser('duelstructure2');

        $gameId = $this->games->createGame($u1, [$u1, $u2], format: 'duel', deckType: 'structure');
        $this->games->startGame($gameId);

        $p1 = $this->games->gamePlayerIdFor($gameId, $u1);
        $p2 = $this->games->gamePlayerIdFor($gameId, $u2);

        $deckStmt = $this->pdo->prepare(
            "SELECT owner_game_player_id, COUNT(*) AS n FROM game_cards WHERE game_id = :game_id AND zone = 'deck' GROUP BY owner_game_player_id"
        );
        $deckStmt->execute(['game_id' => $gameId]);
        $counts = array_column($deckStmt->fetchAll(), 'n', 'owner_game_player_id');

        // 45-card structure deck (23 common + 14 uncommon + 6 rare + 2
        // mythic), minus the 5-card starting hand, independently built for
        // each player -- not a 45-card pool shared/split between them.
        self::assertSame(45 - 5, (int) $counts[$p1]);
        self::assertSame(45 - 5, (int) $counts[$p2]);
    }

    public function testDrawingACardInADuelAlwaysComesFromTheDrawingPlayersOwnDeck(): void
    {
        $u1 = $this->insertUser('dueldraw1');
        $u2 = $this->insertUser('dueldraw2');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('duel', 'in_progress', :created_by, 3)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $p2 = $this->insertGamePlayer($gameId, $u2, 1);

        $zealId = $this->insertGameCard($gameId, 106, 'hand', $p1); // Zeal
        $benevolenceId = $this->insertGameCard($gameId, 2, 'hand', $p1); // Benevolence -- bottomed as Zeal's own cost
        $p1TopCardId = $this->insertGameCard($gameId, 9, 'deck', $p1, 0); // p1's own deck -- what p1 should draw
        $p2TopCardId = $this->insertGameCard($gameId, 8, 'deck', $p2, 0); // p2's own deck -- must NOT be drawn by p1
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $this->games->playMood($gameId, $p1, $zealId, ['hand_card_id' => $benevolenceId]);

        $registry = DefaultEffectRegistry::build();
        $state = (new BoardStateRepository($registry))->load($gameId);

        self::assertTrue($state->isInHand($p1, $p1TopCardId)); // drew from its own deck
        self::assertFalse($state->isInHand($p1, $p2TopCardId)); // never touched p2's own deck
        self::assertSame([$p2TopCardId], $state->deck($p2)); // p2's own deck untouched
        self::assertContains($benevolenceId, $state->deck($p1)); // Zeal's own hand-card cost bottomed into p1's own deck
    }

    public function testMoveInPlayToBottomOfDeckInADuelBottomsIntoTheTargetMoodsOwnersDeck(): void
    {
        $u1 = $this->insertUser('duelconv1');
        $u2 = $this->insertUser('duelconv2');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('duel', 'in_progress', :created_by, 3)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $p2 = $this->insertGamePlayer($gameId, $u2, 1);

        $convictionId = $this->insertGameCard($gameId, 6, 'hand', $p1); // Conviction
        $disciplineId = $this->insertGameCard($gameId, 9, 'in_play', $p2); // Discipline -- p2's own mood, targeted
        $p2TopCardId = $this->insertGameCard($gameId, 8, 'deck', $p2, 0); // p2's own deck -- p2's own consolation draw
        $p1TopCardId = $this->insertGameCard($gameId, 7, 'deck', $p1, 0); // p1's own deck -- must NOT be touched
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $this->games->playMood($gameId, $p1, $convictionId, ['target_mood_id' => $disciplineId]);

        $registry = DefaultEffectRegistry::build();
        $state = (new BoardStateRepository($registry))->load($gameId);

        self::assertFalse($state->isInPlay($disciplineId));
        self::assertContains($disciplineId, $state->deck($p2)); // bottomed into its own owner's (p2's) deck
        self::assertTrue($state->isInHand($p2, $p2TopCardId)); // p2 -- the targeted mood's own owner -- drew from their own deck
        self::assertFalse($state->isInHand($p1, $p2TopCardId));
        self::assertSame([$p1TopCardId], $state->deck($p1)); // p1's own deck untouched
    }

    public function testMoveDiscardToBottomOfDeckInADuelBottomsIntoTheDiscardedCardsOriginalOwnersDeck(): void
    {
        $u1 = $this->insertUser('duelcorrupt1');
        $u2 = $this->insertUser('duelcorrupt2');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('duel', 'in_progress', :created_by, 3)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $p2 = $this->insertGamePlayer($gameId, $u2, 1);

        $corruptionId = $this->insertGameCard($gameId, 60, 'hand', $p1); // Corruption
        $disciplineId = $this->insertGameCard($gameId, 9, 'discard', $p2); // originally p2's own card, now in the shared discard pile
        $p1TopCardId = $this->insertGameCard($gameId, 7, 'deck', $p1, 0); // p1's own deck -- p1 (the acting player) still draws, per Corruption's own text
        $p2TopCardId = $this->insertGameCard($gameId, 8, 'deck', $p2, 0); // p2's own deck -- must NOT be touched
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $this->games->playMood($gameId, $p1, $corruptionId, ['mode' => 'cycle', 'discard_card_ids' => [$disciplineId]]);

        $registry = DefaultEffectRegistry::build();
        $state = (new BoardStateRepository($registry))->load($gameId);

        self::assertFalse($state->isInDiscardPile($disciplineId));
        self::assertContains($disciplineId, $state->deck($p2)); // bottomed into its ORIGINAL owner's (p2's) deck, not p1's (the acting player)
        self::assertTrue($state->isInHand($p1, $p1TopCardId)); // the acting player still draws, from their own deck
        self::assertFalse($state->isInHand($p1, $p2TopCardId));
    }

    public function testGetStateDeckCountShowsTheViewersOwnDeckInADuel(): void
    {
        $u1 = $this->insertUser('dueldeckcount1');
        $u2 = $this->insertUser('dueldeckcount2');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('duel', 'in_progress', :created_by, 3)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $p2 = $this->insertGamePlayer($gameId, $u2, 1);

        $this->insertGameCard($gameId, 7, 'deck', $p1, 0);
        $this->insertGameCard($gameId, 8, 'deck', $p1, 1);
        $this->insertGameCard($gameId, 9, 'deck', $p2, 0);
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        self::assertSame(2, $this->games->getState($gameId, $u1)['deck_count']);
        self::assertSame(1, $this->games->getState($gameId, $u2)['deck_count']);
    }

    public function testRoundEndDrawInADuelPullsFromEachLosersOwnDeck(): void
    {
        $u1 = $this->insertUser('duelroundend1');
        $u2 = $this->insertUser('duelroundend2');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('duel', 'in_progress', :created_by, 3)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $p2 = $this->insertGamePlayer($gameId, $u2, 1);

        $apathyId = $this->insertGameCard($gameId, 55, 'hand', $p1); // Apathy, value 4 -- p1 wins the round
        $p2TopCardId = $this->insertGameCard($gameId, 7, 'deck', $p2, 0); // p2's own deck -- p2's own consolation draw
        $p1TopCardId = $this->insertGameCard($gameId, 8, 'deck', $p1, 0); // p1's own deck -- must stay untouched (p1 won)
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $this->games->playMood($gameId, $p1, $apathyId, []);
        $this->games->pass($gameId, $p2);

        $registry = DefaultEffectRegistry::build();
        $state = (new BoardStateRepository($registry))->load($gameId);

        self::assertTrue($state->isInHand($p2, $p2TopCardId)); // p2 (the loser) drew from their own deck
        self::assertSame([$p1TopCardId], $state->deck($p1)); // p1 (the winner) doesn't draw -- own deck untouched
    }

    public function testStartGameRejectsFewerThanTwoPlayers(): void
    {
        $creator = $this->insertUser('solo');
        $gameId = $this->games->createGame($creator, [$creator]);

        $this->expectException(GameStateException::class);
        $this->games->startGame($gameId);
    }

    public function testCreateGameAllowsFourPlayers(): void
    {
        $creator = $this->insertUser('alice4');
        $bob = $this->insertUser('bob4');
        $carol = $this->insertUser('carol4');
        $dave = $this->insertUser('dave4');

        $gameId = $this->games->createGame($creator, [$creator, $bob, $carol, $dave]);
        $this->games->startGame($gameId);

        self::assertSame('in_progress', $this->fetchGame($gameId)['status']);
    }

    public function testCreateGameRejectsMoreThanFourPlayers(): void
    {
        $creator = $this->insertUser('alice5');
        $others = [
            $this->insertUser('bob5'),
            $this->insertUser('carol5'),
            $this->insertUser('dave5'),
            $this->insertUser('erin5'),
        ];

        $this->expectException(GameStateException::class);
        $this->games->createGame($creator, [$creator, ...$others]);
    }

    public function testGamePlayerIdForReturnsSeatedPlayersIdAndNullOtherwise(): void
    {
        $creator = $this->insertUser('seat-alice');
        $bob = $this->insertUser('seat-bob');
        $stranger = $this->insertUser('seat-stranger');

        $gameId = $this->games->createGame($creator, [$creator, $bob]);

        $stmt = $this->pdo->prepare('SELECT id FROM game_players WHERE game_id = :game_id AND user_id = :user_id');
        $stmt->execute(['game_id' => $gameId, 'user_id' => $creator]);
        $expectedGamePlayerId = (int) $stmt->fetchColumn();

        self::assertSame($expectedGamePlayerId, $this->games->gamePlayerIdFor($gameId, $creator));
        self::assertNull($this->games->gamePlayerIdFor($gameId, $stranger));
    }

    public function testListGamesForUserReturnsSummariesAndYourTurnFlag(): void
    {
        $creator = $this->insertUser('list-alice');
        $bob = $this->insertUser('list-bob');

        $gameId = $this->games->createGame($creator, [$creator, $bob]);
        $this->games->startGame($gameId);

        $creatorGames = $this->games->listGamesForUser($creator);
        $bobGames = $this->games->listGamesForUser($bob);

        self::assertCount(1, $creatorGames);
        $summary = $creatorGames[0];
        self::assertSame($gameId, $summary['id']);
        self::assertSame('in_progress', $summary['status']);
        self::assertCount(2, $summary['players']);
        self::assertNotNull($summary['created_at']);
        self::assertNotNull($summary['started_at']);
        self::assertNull($summary['last_move_at'], 'no move has happened yet');
        self::assertNull($summary['completed_at'], 'the game has not finished yet');

        // Exactly one of the two players is on turn; the flag should
        // disagree between their two lists.
        self::assertNotSame($creatorGames[0]['is_your_turn'], $bobGames[0]['is_your_turn']);
    }

    public function testListGamesForUserSortsWaitingAndInProgressAboveCompletedRegardlessOfRecency(): void
    {
        $creator = $this->insertUser('sortorder-alice');
        $bob = $this->insertUser('sortorder-bob');

        // An old completed game -- most recently *active* of the three (its
        // own completed_at/last_move_at are the newest timestamps here),
        // but should still sort below both a stale waiting game and a
        // stale in-progress one, since neither of those is actionable and
        // this one no longer is.
        $completedId = $this->games->createGame($creator, [$creator, $bob]);
        $this->games->startGame($completedId);
        $this->pdo->prepare(
            "UPDATE games SET status = 'completed', completed_at = NOW(), last_move_at = NOW(), winner_game_player_id = :winner WHERE id = :id"
        )->execute(['winner' => $this->games->gamePlayerIdFor($completedId, $creator), 'id' => $completedId]);

        // A waiting game created before either of the other two.
        $waitingId = $this->games->createGame($creator, [$creator, $bob]);
        $this->pdo->prepare('UPDATE games SET created_at = DATE_SUB(NOW(), INTERVAL 1 DAY) WHERE id = :id')
            ->execute(['id' => $waitingId]);

        // An in-progress game, also older than the completed one, with no
        // last_move_at of its own yet (falls back to started_at).
        $inProgressId = $this->games->createGame($creator, [$creator, $bob]);
        $this->games->startGame($inProgressId);
        $this->pdo->prepare('UPDATE games SET started_at = DATE_SUB(NOW(), INTERVAL 1 HOUR) WHERE id = :id')
            ->execute(['id' => $inProgressId]);

        $gameIds = array_column($this->games->listGamesForUser($creator), 'id');

        self::assertSame(
            [$inProgressId, $waitingId, $completedId],
            $gameIds,
            'in-progress and waiting games must both sort above the completed one, regardless of raw recency',
        );
    }

    public function testPassStampsLastMoveAtButAnIllegalPassDoesNot(): void
    {
        $creator = $this->insertUser('lastmove-alice');
        $bob = $this->insertUser('lastmove-bob');

        $gameId = $this->games->createGame($creator, [$creator, $bob]);
        $this->games->startGame($gameId);

        self::assertNull($this->fetchGame($gameId)['last_move_at'], 'a freshly-started game has had no moves yet');

        $round = $this->fetchRound($gameId);
        $currentTurnPlayerId = (int) $round['current_turn_game_player_id'];
        $turnOrder = [(int) $this->games->gamePlayerIdFor($gameId, $creator), (int) $this->games->gamePlayerIdFor($gameId, $bob)];
        $notOnTurnPlayerId = $turnOrder[0] === $currentTurnPlayerId ? $turnOrder[1] : $turnOrder[0];

        // Rejected before ever reaching the point that would stamp
        // last_move_at -- an illegal attempt is not a move.
        try {
            $this->games->pass($gameId, $notOnTurnPlayerId);
            self::fail('expected GameStateException for passing out of turn');
        } catch (GameStateException) {
        }
        self::assertNull($this->fetchGame($gameId)['last_move_at']);

        $this->games->pass($gameId, $currentTurnPlayerId);
        self::assertNotNull($this->fetchGame($gameId)['last_move_at']);
    }

    public function testGetStateRejectsAViewerWhoIsNotSeated(): void
    {
        $creator = $this->insertUser('state-alice');
        $bob = $this->insertUser('state-bob');
        $stranger = $this->insertUser('state-stranger');

        $gameId = $this->games->createGame($creator, [$creator, $bob]);

        $this->expectException(GameStateException::class);
        $this->games->getState($gameId, $stranger);
    }

    public function testGetStateForWaitingGameOmitsRoundAndHand(): void
    {
        $creator = $this->insertUser('waiting-alice');
        $bob = $this->insertUser('waiting-bob');

        $gameId = $this->games->createGame($creator, [$creator, $bob]);

        $state = $this->games->getState($gameId, $creator);

        self::assertSame('waiting', $state['game']['status']);
        self::assertNull($state['round']);
        self::assertArrayNotHasKey('hand', $state['you']);
        self::assertCount(2, $state['players']);
    }

    public function testGetStateForInProgressGameExposesYourHandAndHidesOpponentsHand(): void
    {
        $creator = $this->insertUser('board-alice');
        $bob = $this->insertUser('board-bob');

        // deckType is pinned to 'one_of_each' for the same reason as
        // testCreateGameAndStartGameDealsCardsAndBeginsFirstRound() above --
        // this test's own deck_count assertion below relies on the full
        // 133-card pool.
        $gameId = $this->games->createGame($creator, [$creator, $bob], deckType: 'one_of_each');
        $this->games->startGame($gameId);

        $creatorGamePlayerId = $this->games->gamePlayerIdFor($gameId, $creator);
        $state = $this->games->getState($gameId, $creator);

        self::assertSame('in_progress', $state['game']['status']);
        self::assertNotNull($state['round']);
        self::assertCount(5, $state['you']['hand']);
        self::assertSame($creatorGamePlayerId, $state['you']['game_player_id']);

        foreach ($state['players'] as $player) {
            self::assertSame(5, $player['hand_count']);
        }

        self::assertSame(133 - 5 * 2, $state['deck_count']);
        self::assertSame([], $state['discard_pile']);
        self::assertSame([], $state['in_play']);

        foreach ($state['you']['hand'] as $card) {
            self::assertArrayHasKey('name', $card);
            self::assertArrayHasKey('color', $card);
            self::assertArrayHasKey('value', $card);
            self::assertArrayHasKey('choice_fields', $card);
            self::assertIsArray($card['choice_fields']);
            self::assertArrayHasKey('has_dice_value', $card);
            self::assertIsBool($card['has_dice_value']);
            self::assertArrayHasKey('base_value', $card);
            self::assertIsInt($card['base_value']);
            self::assertArrayHasKey('alt_value', $card);
            self::assertSame($card['alt_value'] !== null, $card['has_dice_value']);
        }
    }

    public function testGetStateExposesBaseValueAndAltValueDistinctFromLiveValue(): void
    {
        $u1 = $this->insertUser('printedvalues1');
        $u2 = $this->insertUser('printedvalues2');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('standard', 'in_progress', :created_by, 3)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $this->insertGamePlayer($gameId, $u2, 1);

        $dignityId = $this->insertGameCard($gameId, 8, 'hand', $p1); // Dignity: base 3, dice/alt 5
        $charityId = $this->insertGameCard($gameId, 3, 'hand', $p1); // Charity: base 1, no dice value
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $hand = $this->games->getState($gameId, $u1)['you']['hand'];
        $byCardId = [];
        foreach ($hand as $card) {
            $byCardId[$card['card_id']] = $card;
        }

        self::assertSame(3, $byCardId[$dignityId]['base_value']);
        self::assertSame(5, $byCardId[$dignityId]['alt_value']);
        self::assertTrue($byCardId[$dignityId]['has_dice_value']);
        self::assertSame(3, $byCardId[$dignityId]['value']); // not in play yet -- value equals base_value

        self::assertSame(1, $byCardId[$charityId]['base_value']);
        self::assertNull($byCardId[$charityId]['alt_value']);
        self::assertFalse($byCardId[$charityId]['has_dice_value']);
    }

    public function testGetStateAppendsScornsReactionFieldFilteredByEachHandCardsOwnColor(): void
    {
        $u1 = $this->insertUser('scornreact1');
        $u2 = $this->insertUser('scornreact2');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('standard', 'in_progress', :created_by, 3)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $this->insertGamePlayer($gameId, $u2, 1);

        $this->insertGameCard($gameId, 24, 'in_play', $p1); // Scorn, white
        $courageId = $this->insertGameCard($gameId, 7, 'hand', $p1); // Courage, white
        $anxietyId = $this->insertGameCard($gameId, 28, 'hand', $p1); // Anxiety, blue
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $hand = $this->games->getState($gameId, $u1)['you']['hand'];
        $byCardId = [];
        foreach ($hand as $card) {
            $byCardId[$card['card_id']] = $card;
        }

        $courageReaction = self::findFieldByKey($byCardId[$courageId]['choice_fields'], 'scorn_suppress_target');
        self::assertNotNull($courageReaction, 'expected a Scorn reaction field on the white Courage card');
        self::assertSame(['white'], $courageReaction['filter']['colors']);

        $anxietyReaction = self::findFieldByKey($byCardId[$anxietyId]['choice_fields'], 'scorn_suppress_target');
        self::assertNotNull($anxietyReaction, 'expected a Scorn reaction field on the blue Anxiety card');
        self::assertSame(['blue'], $anxietyReaction['filter']['colors']);
    }

    public function testGetStateAppendsValidationsReactionFieldOnlyForZeroOrOneValueCards(): void
    {
        $u1 = $this->insertUser('validationreact1');
        $u2 = $this->insertUser('validationreact2');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('standard', 'in_progress', :created_by, 3)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $this->insertGamePlayer($gameId, $u2, 1);

        $this->insertGameCard($gameId, 26, 'in_play', $p1); // Validation
        $charityId = $this->insertGameCard($gameId, 3, 'hand', $p1); // Charity, base value 1 -- qualifies
        $dignityId = $this->insertGameCard($gameId, 8, 'hand', $p1); // Dignity, base value 3 -- doesn't qualify
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $hand = $this->games->getState($gameId, $u1)['you']['hand'];
        $byCardId = [];
        foreach ($hand as $card) {
            $byCardId[$card['card_id']] = $card;
        }

        self::assertNotNull(self::findFieldByKey($byCardId[$charityId]['choice_fields'], 'validation_extra_play'));
        self::assertNull(self::findFieldByKey($byCardId[$dignityId]['choice_fields'], 'validation_extra_play'));
    }

    public function testGetStateOmitsReactionFieldsWhenViewerHasNoReactorInPlay(): void
    {
        $u1 = $this->insertUser('noreactor1');
        $u2 = $this->insertUser('noreactor2');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('standard', 'in_progress', :created_by, 3)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $this->insertGamePlayer($gameId, $u2, 1);

        $this->insertGameCard($gameId, 3, 'hand', $p1); // Charity, base value 1 -- would qualify for Validation's reaction, but no reactor is in play
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $hand = $this->games->getState($gameId, $u1)['you']['hand'];

        self::assertNull(self::findFieldByKey($hand[0]['choice_fields'], 'scorn_suppress_target'));
        self::assertNull(self::findFieldByKey($hand[0]['choice_fields'], 'validation_extra_play'));
    }

    /**
     * Duplicity's repeat option is no longer a pre-submitted top-level
     * choice_fields entry -- it's a pending decision targeting the ACTING
     * player themselves, offered only once the played card's own
     * after-playing effect (here Dignity's) has already resolved. Every
     * other viewer sees who it's waiting on but not the actual field,
     * same hidden-information scoping as an opponent's own decision.
     */
    public function testGetStateExposesDuplicitysRepeatOfferAsAPendingDecisionForTheActingPlayer(): void
    {
        $u1 = $this->insertUser('duplicity1');
        $u2 = $this->insertUser('duplicity2');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('standard', 'in_progress', :created_by, 3)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $p2 = $this->insertGamePlayer($gameId, $u2, 1);

        $this->insertGameCard($gameId, 37, 'in_play', $p1); // Duplicity
        $dignityId = $this->insertGameCard($gameId, 8, 'hand', $p1); // Dignity -- has its own afterPlaying choice
        $charityId = $this->insertGameCard($gameId, 3, 'hand', $p1); // Charity, value 1 -- discard fodder
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $playResult = $this->games->playMood($gameId, $p1, $dignityId, ['discard_card_id' => $charityId]);
        self::assertTrue($playResult['pending_decision'] ?? false);

        $pending = $this->games->getState($gameId, $u1)['round']['pending_decision'];
        self::assertSame($p1, $pending['target_game_player_id']);
        self::assertTrue($pending['is_you']);
        self::assertSame('duplicity_repeat_offer', $pending['decision_type']);
        self::assertSame('duplicity_repeat', $pending['field']['key']);
        self::assertSame('nested', $pending['field']['type']);
        self::assertSame('repeat', $pending['field']['fields'][0]['key']);
        self::assertSame('bool', $pending['field']['fields'][0]['type']);
        $choicesField = $pending['field']['fields'][1];
        self::assertSame('choices', $choicesField['key']);
        self::assertCount(1, $choicesField['fields']);
        self::assertSame('discard_card_id', $choicesField['fields'][0]['key']);

        // A bystander sees who it's waiting on, never the actual field.
        $bystanderPending = $this->games->getState($gameId, $u2)['round']['pending_decision'];
        self::assertFalse($bystanderPending['is_you']);
        self::assertArrayNotHasKey('field', $bystanderPending);
    }

    /**
     * Guile's own "to play" cost field never belongs in a repeat's own
     * choices -- a repeat only re-invokes afterPlaying(), never re-pays a
     * cost already paid once when Guile was originally played -- mirrors
     * CardChoiceSchema::afterPlayingFields()'s own stage:'cost' exclusion,
     * now consumed by MoodPlayService::duplicityRepeatOfferRequest()
     * instead of the old choice_fields-level duplicityFields().
     */
    public function testDuplicitysRepeatOfferExcludesGuilesCostFieldButKeepsItsTargetField(): void
    {
        $u1 = $this->insertUser('guiledup1');
        $u2 = $this->insertUser('guiledup2');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('standard', 'in_progress', :created_by, 3)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $p2 = $this->insertGamePlayer($gameId, $u2, 1);

        $this->insertGameCard($gameId, 37, 'in_play', $p1); // Duplicity
        $guileId = $this->insertGameCard($gameId, 40, 'hand', $p1); // Guile -- discard cost + afterPlaying target
        $discard1Id = $this->insertGameCard($gameId, 3, 'hand', $p1);
        $discard2Id = $this->insertGameCard($gameId, 7, 'hand', $p1); // Guile's own 2-card discard cost
        $targetMoodId = $this->insertGameCard($gameId, 9, 'in_play', $p2); // Guile's own afterPlaying target
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $playResult = $this->games->playMood($gameId, $p1, $guileId, ['discard_card_ids' => [$discard1Id, $discard2Id], 'target_mood_id' => $targetMoodId]);
        self::assertTrue($playResult['pending_decision'] ?? false);

        $pending = $this->games->getState($gameId, $u1)['round']['pending_decision'];
        $choicesField = $pending['field']['fields'][1];
        self::assertSame('choices', $choicesField['key']);
        self::assertCount(1, $choicesField['fields']);
        self::assertSame('target_mood_id', $choicesField['fields'][0]['key']);
    }

    public function testCopySimulationIsNullForNonCreativityCards(): void
    {
        $u1 = $this->insertUser('copysimnull1');
        $u2 = $this->insertUser('copysimnull2');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('standard', 'in_progress', :created_by, 3)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $this->insertGamePlayer($gameId, $u2, 1);

        $dignityId = $this->insertGameCard($gameId, 8, 'hand', $p1); // Dignity
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $hand = $this->games->getState($gameId, $u1)['you']['hand'];

        self::assertNull(self::findByCardId($hand, $dignityId)['copy_simulation']);
    }

    /**
     * Creativity's own choice_fields never gets a duplicity_repeat field
     * (its raw hasAfterPlaying is false), but copy_simulation -- keyed by
     * every in-play candidate -- has to offer the repeat option for a
     * candidate that DOES have its own after-playing ability, using that
     * candidate's own afterPlayingFields() for the nested sub-form, the
     * same way an ordinary after-playing hand card already would. A
     * candidate that's itself Duplicity is excluded, mirroring
     * duplicityFields()'s own effectKey === 'duplicity' check -- copying
     * Duplicity shouldn't offer to repeat Duplicity's own effect.
     */
    /**
     * Duplicity's repeat is no longer part of copy_simulation at all --
     * once a Creativity play is actually in play (real or copied), the
     * same pause-based repeat-offer mechanism applies uniformly (see
     * MoodPlayService::continueAfterPlayingChain()), so there's nothing
     * copy-specific left to precompute in the panel here.
     */
    public function testCopySimulationNeverIncludesDuplicitysRepeatSinceItsNowAPostPlayPause(): void
    {
        $u1 = $this->insertUser('copysimdup1');
        $u2 = $this->insertUser('copysimdup2');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('standard', 'in_progress', :created_by, 3)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $p2 = $this->insertGamePlayer($gameId, $u2, 1);

        $this->insertGameCard($gameId, 37, 'in_play', $p1); // Duplicity, owned by the viewer
        $creativityId = $this->insertGameCard($gameId, 32, 'hand', $p1); // Creativity
        $dignityId = $this->insertGameCard($gameId, 8, 'in_play', $p2); // Dignity -- has its own afterPlaying
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $hand = $this->games->getState($gameId, $u1)['you']['hand'];
        $creativity = self::findByCardId($hand, $creativityId);

        self::assertNull(self::findFieldByKey($creativity['copy_simulation'][$dignityId]['extra_fields'], 'duplicity_repeat'));
    }

    public function testCopySimulationOffersScornsReactionFilteredToTheCandidatesRawColor(): void
    {
        $u1 = $this->insertUser('copysimscorn1');
        $u2 = $this->insertUser('copysimscorn2');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('standard', 'in_progress', :created_by, 3)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $p2 = $this->insertGamePlayer($gameId, $u2, 1);

        $this->insertGameCard($gameId, 24, 'in_play', $p1); // Scorn, owned by the viewer
        $creativityId = $this->insertGameCard($gameId, 32, 'hand', $p1); // Creativity
        $dignityId = $this->insertGameCard($gameId, 8, 'in_play', $p2); // Dignity, white
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $hand = $this->games->getState($gameId, $u1)['you']['hand'];
        $creativity = self::findByCardId($hand, $creativityId);

        $scornField = self::findFieldByKey($creativity['copy_simulation'][$dignityId]['extra_fields'], 'scorn_suppress_target');
        self::assertNotNull($scornField);
        self::assertSame(['white'], $scornField['filter']['colors']);
    }

    public function testCopySimulationOffersValidationsReactionOnlyForZeroOrOneValueCandidates(): void
    {
        $u1 = $this->insertUser('copysimvalid1');
        $u2 = $this->insertUser('copysimvalid2');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('standard', 'in_progress', :created_by, 3)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $p2 = $this->insertGamePlayer($gameId, $u2, 1);

        $this->insertGameCard($gameId, 26, 'in_play', $p1); // Validation, owned by the viewer
        $creativityId = $this->insertGameCard($gameId, 32, 'hand', $p1); // Creativity
        $guileId = $this->insertGameCard($gameId, 40, 'in_play', $p2); // Guile, base value 0 -- qualifies
        $dignityId = $this->insertGameCard($gameId, 8, 'in_play', $p2); // Dignity, base value 3 -- doesn't qualify
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $hand = $this->games->getState($gameId, $u1)['you']['hand'];
        $creativity = self::findByCardId($hand, $creativityId);

        self::assertNotNull(self::findFieldByKey($creativity['copy_simulation'][$guileId]['extra_fields'], 'validation_extra_play'));
        self::assertNull(self::findFieldByKey($creativity['copy_simulation'][$dignityId]['extra_fields'], 'validation_extra_play'));
    }

    public function testInPlayCreativityCopyDisplaysAsTheCopiedMoodWithACopyIndicator(): void
    {
        $u1 = $this->insertUser('creativitydisplay1');
        $u2 = $this->insertUser('creativitydisplay2');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('standard', 'in_progress', :created_by, 3)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $this->insertGamePlayer($gameId, $u2, 1);

        $serenityId = $this->insertGameCard($gameId, 129, 'in_play', $p1); // real Serenity
        $creativityId = $this->insertGameCard($gameId, 32, 'in_play', $p1); // Creativity, copying Serenity
        $this->pdo->prepare('UPDATE game_cards SET copied_card_id = :copied_card_id WHERE id = :id')
            ->execute(['copied_card_id' => $serenityId, 'id' => $creativityId]);
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $inPlay = $this->games->getState($gameId, $u1)['in_play'];
        $creativity = self::findByCardId($inPlay, $creativityId);

        self::assertSame('Serenity', $creativity['name']);
        self::assertSame('serenity', $creativity['effect_key']);
        self::assertSame(
            "While in play, this mood's value is 6 if you have an even number of moods, including this one.",
            $creativity['rules_text'],
        );
        self::assertTrue($creativity['is_creativity_copy']);

        // The real Serenity sitting alongside it is unaffected -- only the
        // Creativity instance itself displays as a copy.
        $realSerenity = self::findByCardId($inPlay, $serenityId);
        self::assertSame('Serenity', $realSerenity['name']);
        self::assertFalse($realSerenity['is_creativity_copy']);
    }

    public function testInPlayCreativityWithNoCopyChosenStillDisplaysAsPlainCreativity(): void
    {
        $u1 = $this->insertUser('creativityblank1');
        $u2 = $this->insertUser('creativityblank2');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('standard', 'in_progress', :created_by, 3)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $this->insertGamePlayer($gameId, $u2, 1);

        // Creativity played "blank," copying nothing -- copied_card_id
        // stays NULL, exactly as an uncopied real play leaves it.
        $creativityId = $this->insertGameCard($gameId, 32, 'in_play', $p1);
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $creativity = self::findByCardId($this->games->getState($gameId, $u1)['in_play'], $creativityId);

        self::assertSame('Creativity', $creativity['name']);
        self::assertSame('creativity', $creativity['effect_key']);
        self::assertFalse($creativity['is_creativity_copy']);
    }

    /**
     * A Creativity copying Bliss behaves as Bliss for scoring purposes
     * (RoundScorer::score() already resolves effect_key through
     * effectiveCardId()) -- this proves the display-facing bliss_discard_color
     * field (GameService::getState()'s in_play mapping, keyed off the
     * serialized card's own effect_key) now agrees, since payToPlayCost()
     * always tags 'blissColor' on the *playing* card's own id (the
     * Creativity instance), matching what serializeCard() reads it back
     * from once effect_key correctly reads as 'bliss' too.
     */
    public function testInPlayCreativityCopyOfBlissExposesBlissDiscardColorToo(): void
    {
        $u1 = $this->insertUser('creativitybliss1');
        $u2 = $this->insertUser('creativitybliss2');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('standard', 'in_progress', :created_by, 3)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $this->insertGamePlayer($gameId, $u2, 1);

        $blissId = $this->insertGameCard($gameId, 108, 'in_play', $p1); // real Bliss
        $creativityId = $this->insertGameCard($gameId, 32, 'in_play', $p1); // Creativity, copying Bliss
        $this->pdo->prepare('UPDATE game_cards SET copied_card_id = :copied_card_id, effect_state = :effect_state WHERE id = :id')
            ->execute(['copied_card_id' => $blissId, 'effect_state' => json_encode(['blissColor' => 'white']), 'id' => $creativityId]);
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $creativity = self::findByCardId($this->games->getState($gameId, $u1)['in_play'], $creativityId);

        self::assertSame('Bliss', $creativity['name']);
        self::assertTrue($creativity['is_creativity_copy']);
        self::assertSame('white', $creativity['bliss_discard_color']);
    }

    public function testCopySimulationCostPayableReflectsTheCandidatesOwnCostExcludingCreativitysOwnHandSlot(): void
    {
        $u1 = $this->insertUser('copysimcost1');
        $u2 = $this->insertUser('copysimcost2');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('standard', 'in_progress', :created_by, 3)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $p2 = $this->insertGamePlayer($gameId, $u2, 1);

        $creativityId = $this->insertGameCard($gameId, 32, 'hand', $p1); // Creativity, alone in hand
        $guileId = $this->insertGameCard($gameId, 40, 'in_play', $p2); // Guile -- needs 2 *other* hand cards to discard
        $dignityId = $this->insertGameCard($gameId, 8, 'in_play', $p2); // Dignity -- no "to play" cost at all
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $creativity = self::findByCardId($this->games->getState($gameId, $u1)['you']['hand'], $creativityId);
        self::assertFalse($creativity['copy_simulation'][$guileId]['cost_payable']);
        self::assertTrue($creativity['copy_simulation'][$dignityId]['cost_payable']);

        $this->insertGameCard($gameId, 3, 'hand', $p1); // Charity
        $this->insertGameCard($gameId, 4, 'hand', $p1); // Chivalry -- now 2 other hand cards exist

        $creativity = self::findByCardId($this->games->getState($gameId, $u1)['you']['hand'], $creativityId);
        self::assertTrue($creativity['copy_simulation'][$guileId]['cost_payable']);
    }

    /**
     * Proves the whole mechanism end-to-end, not just the panel metadata:
     * playing Creativity as a copy of Dignity, with Duplicity in play,
     * pauses for the acting player's own "repeat again?" offer exactly
     * like a real Dignity play would -- answering it has to actually
     * invoke Dignity's own afterPlaying() a second time, discarding a
     * second hand card and boosting Creativity's own live value to 5.
     * MoodPlayService's effective-aware repeat/reaction machinery already
     * supported this before this change; only the panel never offered it.
     */
    public function testPlayingCreativityAsACopyCorrectlyRepeatsTheCopiedEffectViaDuplicity(): void
    {
        $u1 = $this->insertUser('copyplay1');
        $u2 = $this->insertUser('copyplay2');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('standard', 'in_progress', :created_by, 3)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $p2 = $this->insertGamePlayer($gameId, $u2, 1);

        $this->insertGameCard($gameId, 37, 'in_play', $p1); // Duplicity
        $creativityId = $this->insertGameCard($gameId, 32, 'hand', $p1); // Creativity
        $charityId = $this->insertGameCard($gameId, 3, 'hand', $p1); // Charity, value 1 -- discarded by the first invocation
        $chivalryId = $this->insertGameCard($gameId, 4, 'hand', $p1); // Chivalry, value 3 -- discarded by the repeat
        $dignityId = $this->insertGameCard($gameId, 8, 'in_play', $p2); // Dignity -- the copy target

        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $playResult = $this->games->playMood($gameId, $p1, $creativityId, [
            'copy_card_id' => $dignityId,
            'discard_card_id' => $charityId,
        ]);
        self::assertTrue($playResult['pending_decision'] ?? false);

        $respondResult = $this->games->respondToDecision($gameId, $p1, [
            'duplicity_repeat' => ['repeat' => true, 'choices' => ['discard_card_id' => $chivalryId]],
        ]);
        self::assertArrayNotHasKey('pending_decision', $respondResult);

        $registry = DefaultEffectRegistry::build();
        $state = (new BoardStateRepository($registry))->load($gameId);

        self::assertSame([], $state->hand($p1));
        self::assertSame([$charityId, $chivalryId], $state->discardPile());
        self::assertTrue($state->isInPlay($creativityId));
        self::assertSame('white', $state->colorOf($creativityId)); // Dignity's color, confirming the copy took effect
        self::assertSame(5, $state->valueOf($creativityId));
    }

    /**
     * The real end-to-end version of the "repeat of a repeat" fix: a real
     * Duplicity plus a Creativity already copying one, both in play,
     * grant two independent repeats of the played card's own effect --
     * each offered and answered one at a time through the real HTTP-
     * service-layer respondToDecision() flow, not pre-declared all at
     * once. Only reachable this way since every card, Duplicity included,
     * is single-copy.
     */
    public function testTwoIndependentDuplicitySourcesGrantTwoChainedRepeatsThroughRespondToDecision(): void
    {
        $u1 = $this->insertUser('twodup1');
        $u2 = $this->insertUser('twodup2');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('standard', 'in_progress', :created_by, 3)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $this->insertGamePlayer($gameId, $u2, 1);

        $duplicityId = $this->insertGameCard($gameId, 37, 'in_play', $p1); // real Duplicity
        $creativityId = $this->insertGameCard($gameId, 32, 'in_play', $p1); // Creativity, already copying Duplicity
        $this->pdo->prepare('UPDATE game_cards SET copied_card_id = :copied_card_id WHERE id = :id')
            ->execute(['copied_card_id' => $duplicityId, 'id' => $creativityId]);
        $dignityId = $this->insertGameCard($gameId, 8, 'hand', $p1); // Dignity
        $charityId = $this->insertGameCard($gameId, 3, 'hand', $p1); // Charity, value 1
        $chivalryId = $this->insertGameCard($gameId, 4, 'hand', $p1); // Chivalry, value 3
        $convictionId = $this->insertGameCard($gameId, 6, 'hand', $p1); // Conviction, value 2
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $playResult = $this->games->playMood($gameId, $p1, $dignityId, ['discard_card_id' => $charityId]);
        self::assertTrue($playResult['pending_decision'] ?? false);

        $respondResult1 = $this->games->respondToDecision($gameId, $p1, [
            'duplicity_repeat' => ['repeat' => true, 'choices' => ['discard_card_id' => $chivalryId]],
        ]);
        self::assertTrue($respondResult1['pending_decision'] ?? false, 'a second independent Duplicity source should still be available');

        $respondResult2 = $this->games->respondToDecision($gameId, $p1, [
            'duplicity_repeat' => ['repeat' => true, 'choices' => ['discard_card_id' => $convictionId]],
        ]);
        self::assertArrayNotHasKey('pending_decision', $respondResult2);

        $registry = DefaultEffectRegistry::build();
        $state = (new BoardStateRepository($registry))->load($gameId);

        self::assertSame([], $state->hand($p1));
        self::assertSame([$charityId, $chivalryId, $convictionId], $state->discardPile());
        self::assertSame(5, $state->valueOf($dignityId));
    }

    public function testGetStateMarksOnlyTheCardARestrictedGrantCoversAsPlayable(): void
    {
        $u1 = $this->insertUser('restrictedgrant1');
        $u2 = $this->insertUser('restrictedgrant2');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('standard', 'in_progress', :created_by, 3)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $this->insertGamePlayer($gameId, $u2, 1);

        $charityId = $this->insertGameCard($gameId, 3, 'hand', $p1); // Charity -- the one card the grant below covers
        $courageId = $this->insertGameCard($gameId, 7, 'hand', $p1); // Courage -- not covered
        $roundId = $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        // Mirrors the grant IntimidationEffect hands out, restricted to one
        // specific revealed card -- see BoardState::grantAllows()'s
        // 'specific_card_ids' case.
        $this->pdo->prepare('UPDATE game_rounds SET pending_play_grants = :grants WHERE id = :id')
            ->execute(['grants' => json_encode([['type' => 'specific_card_ids', 'values' => [$charityId]]]), 'id' => $roundId]);

        $hand = $this->games->getState($gameId, $u1)['you']['hand'];

        self::assertTrue(self::findByCardId($hand, $charityId)['is_playable']);
        self::assertFalse(self::findByCardId($hand, $courageId)['is_playable']);
    }

    public function testGetStateMarksAnUnaffordableToPlayCostCardUnplayable(): void
    {
        $u1 = $this->insertUser('unaffordablecost1');
        $u2 = $this->insertUser('unaffordablecost2');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('standard', 'in_progress', :created_by, 3)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $this->insertGamePlayer($gameId, $u2, 1);

        $guileId = $this->insertGameCard($gameId, 40, 'hand', $p1); // Guile, alone -- needs 2 *other* hand cards to discard
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $hand = $this->games->getState($gameId, $u1)['you']['hand'];

        self::assertFalse(self::findByCardId($hand, $guileId)['is_playable']);
    }

    public function testGetStateMarksHandCardsUnplayableWhenItIsNotTheViewersTurn(): void
    {
        $u1 = $this->insertUser('notyourturn1');
        $u2 = $this->insertUser('notyourturn2');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('standard', 'in_progress', :created_by, 3)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $p2 = $this->insertGamePlayer($gameId, $u2, 1);

        $charityId = $this->insertGameCard($gameId, 3, 'hand', $p1); // Charity -- otherwise unconditionally playable
        $this->insertGameRound($gameId, 1, $p2, $p2, 1); // p2's turn, not p1's

        $hand = $this->games->getState($gameId, $u1)['you']['hand'];

        self::assertFalse(self::findByCardId($hand, $charityId)['is_playable']);
    }

    public function testGetStateExposesSuppressionSourceAndExpiryForAnInPlayMood(): void
    {
        $u1 = $this->insertUser('suppressionsrc1');
        $u2 = $this->insertUser('suppressionsrc2');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('standard', 'in_progress', :created_by, 3)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $this->insertGamePlayer($gameId, $u2, 1);

        $faithId = $this->insertGameCard($gameId, 12, 'in_play', $p1); // Faith
        $courageId = $this->insertGameCard($gameId, 7, 'in_play', $p1); // Courage -- suppressed by Faith, for as long as Faith is in play
        $dignityId = $this->insertGameCard($gameId, 8, 'in_play', $p1); // Dignity -- suppressed until the end of the round, no tracked source (mirrors Repentance)
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $this->pdo->prepare(
            "UPDATE game_cards SET is_suppressed = 1, suppression_expiry = 'while_source_in_play', suppression_source_game_card_id = :source
             WHERE id = :id"
        )->execute(['source' => $faithId, 'id' => $courageId]);

        $this->pdo->prepare(
            "UPDATE game_cards SET is_suppressed = 1, suppression_expiry = 'end_of_round'
             WHERE id = :id"
        )->execute(['id' => $dignityId]);

        $inPlay = $this->games->getState($gameId, $u1)['in_play'];

        $courage = self::findByCardId($inPlay, $courageId);
        self::assertTrue($courage['is_suppressed']);
        self::assertSame('while_source_in_play', $courage['suppression_expiry']);
        self::assertSame($faithId, $courage['suppressed_by_card_id']);
        self::assertSame('Faith', $courage['suppressed_by_name']);

        $dignity = self::findByCardId($inPlay, $dignityId);
        self::assertTrue($dignity['is_suppressed']);
        self::assertSame('end_of_round', $dignity['suppression_expiry']);
        self::assertNull($dignity['suppressed_by_card_id']);
        self::assertNull($dignity['suppressed_by_name']);

        $faith = self::findByCardId($inPlay, $faithId);
        self::assertFalse($faith['is_suppressed']);
    }

    public function testGetStateExposesAffectingAndBoostedByReminderTextForDiceValueAndSuppression(): void
    {
        $u1 = $this->insertUser('affectingsrc1');
        $u2 = $this->insertUser('affectingsrc2');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('standard', 'in_progress', :created_by, 3)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $this->insertGamePlayer($gameId, $u2, 1);

        $encouragementId = $this->insertGameCard($gameId, 11, 'in_play', $p1); // Encouragement
        $dignityId = $this->insertGameCard($gameId, 8, 'in_play', $p1); // Dignity (base 3, dice 5) -- boosted by Encouragement
        $faithId = $this->insertGameCard($gameId, 12, 'in_play', $p1); // Faith
        $courageId = $this->insertGameCard($gameId, 7, 'in_play', $p1); // Courage -- suppressed by Faith
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $this->pdo->prepare(
            'UPDATE game_cards SET effect_state = :effect_state WHERE id = :id'
        )->execute(['effect_state' => json_encode(['boostedMoodId' => $dignityId]), 'id' => $encouragementId]);

        $this->pdo->prepare(
            "UPDATE game_cards SET is_suppressed = 1, suppression_expiry = 'while_source_in_play', suppression_source_game_card_id = :source
             WHERE id = :id"
        )->execute(['source' => $faithId, 'id' => $courageId]);

        $inPlay = $this->games->getState($gameId, $u1)['in_play'];

        $dignity = self::findByCardId($inPlay, $dignityId);
        self::assertSame(5, $dignity['value']); // max(base 3, dice 5)
        self::assertSame($encouragementId, $dignity['boosted_by_card_id']);
        self::assertSame('Encouragement', $dignity['boosted_by_name']);

        $encouragement = self::findByCardId($inPlay, $encouragementId);
        self::assertNull($encouragement['boosted_by_card_id']);
        self::assertSame([['card_id' => $dignityId, 'name' => 'Dignity', 'relationship' => 'dice_value']], $encouragement['affecting']);

        $faith = self::findByCardId($inPlay, $faithId);
        self::assertSame([['card_id' => $courageId, 'name' => 'Courage', 'relationship' => 'suppressed']], $faith['affecting']);

        $courage = self::findByCardId($inPlay, $courageId);
        self::assertSame([], $courage['affecting']);
        self::assertNull($courage['boosted_by_card_id']); // has no printed dice value at all
    }

    public function testGetStateExposesScoringEffectsForEveryInPlayCardThatAffectsScoring(): void
    {
        $u1 = $this->insertUser('scoringfx1');
        $u2 = $this->insertUser('scoringfx2');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('standard', 'in_progress', :created_by, 3)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $p2 = $this->insertGamePlayer($gameId, $u2, 1);

        $blissId = $this->insertGameCard($gameId, 108, 'in_play', $p1); // Bliss
        $exhilarationId = $this->insertGameCard($gameId, 89, 'in_play', $p1); // Exhilaration
        $sneakinessId = $this->insertGameCard($gameId, 51, 'in_play', $p1); // Sneakiness
        $aweId = $this->insertGameCard($gameId, 107, 'in_play', $p2); // Awe
        $corruptionId = $this->insertGameCard($gameId, 60, 'in_play', $p2); // Corruption
        $enthusiasmId = $this->insertGameCard($gameId, 116, 'in_play', $p1); // Enthusiasm
        $passionId = $this->insertGameCard($gameId, 97, 'in_play', $p2); // Passion
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $setEffectState = function (int $cardId, array $effectState): void {
            $this->pdo->prepare('UPDATE game_cards SET effect_state = :effect_state WHERE id = :id')
                ->execute(['effect_state' => json_encode($effectState), 'id' => $cardId]);
        };
        $setEffectState($blissId, ['blissColor' => 'red']);
        $setEffectState($sneakinessId, ['swapScoreWithPlayerId' => $p2]);
        $setEffectState($aweId, ['skipScoringThisRound' => true, 'oneTimeFirstPlayerOverride' => $p2]);
        $setEffectState($corruptionId, ['awardsExtraWin' => true]);

        $effects = $this->games->getState($gameId, $u1)['round']['scoring_effects'];
        $byCardId = [];
        foreach ($effects as $entry) {
            $byCardId[$entry['card_id']] = $entry;
        }
        self::assertCount(7, $effects);

        self::assertSame($p1, $byCardId[$blissId]['owner_game_player_id']);
        self::assertStringContainsString("scoringfx1's Bliss scores their red moods two extra times", $byCardId[$blissId]['description']);

        self::assertStringContainsString("scoringfx1's Exhilaration scores all of their moods an extra time.", $byCardId[$exhilarationId]['description']);

        self::assertStringContainsString("scoringfx1's Sneakiness will swap their round score with scoringfx2's.", $byCardId[$sneakinessId]['description']);

        self::assertSame($p2, $byCardId[$aweId]['owner_game_player_id']);
        self::assertStringContainsString("scoringfx2's Awe means this round won't be scored", $byCardId[$aweId]['description']);

        self::assertStringContainsString("This round's winner will get two wins instead of one (Corruption).", $byCardId[$corruptionId]['description']);

        self::assertStringContainsString('scoringfx1 may score their highest-valued mood an extra time (Enthusiasm).', $byCardId[$enthusiasmId]['description']);

        self::assertStringContainsString("scoringfx2 may score one of an opponent's moods as their own (Passion).", $byCardId[$passionId]['description']);
    }

    public function testGetStateOmitsCorruptionFromScoringEffectsWhenItDidNotChooseTheDoubleWinMode(): void
    {
        $u1 = $this->insertUser('corruptcycle1');
        $u2 = $this->insertUser('corruptcycle2');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('standard', 'in_progress', :created_by, 3)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $this->insertGamePlayer($gameId, $u2, 1);

        // No effect_state tagged at all -- e.g. Corruption cycled discard
        // cards instead of choosing double_win, or declined its ability
        // entirely (both leave no 'awardsExtraWin' tag).
        $this->insertGameCard($gameId, 60, 'in_play', $p1); // Corruption
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        self::assertSame([], $this->games->getState($gameId, $u1)['round']['scoring_effects']);
    }

    public function testGetStateExposesBlissDiscardColorOnItsOwnInPlayCardOnly(): void
    {
        $u1 = $this->insertUser('blisscolor1');
        $u2 = $this->insertUser('blisscolor2');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('standard', 'in_progress', :created_by, 3)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $this->insertGamePlayer($gameId, $u2, 1);

        $blissId = $this->insertGameCard($gameId, 108, 'in_play', $p1); // Bliss
        $courageId = $this->insertGameCard($gameId, 7, 'in_play', $p1); // Courage -- an unrelated card
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $this->pdo->prepare('UPDATE game_cards SET effect_state = :effect_state WHERE id = :id')
            ->execute(['effect_state' => json_encode(['blissColor' => 'blue']), 'id' => $blissId]);

        $inPlay = $this->games->getState($gameId, $u1)['in_play'];

        self::assertSame('blue', self::findByCardId($inPlay, $blissId)['bliss_discard_color']);
        self::assertNull(self::findByCardId($inPlay, $courageId)['bliss_discard_color']);
    }

    public function testGetStateExposesBoardEffectsForImaginationOverridingEveryMoodsColor(): void
    {
        $u1 = $this->insertUser('boardfx1');
        $u2 = $this->insertUser('boardfx2');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('standard', 'in_progress', :created_by, 3)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $this->insertGamePlayer($gameId, $u2, 1);

        $imaginationId = $this->insertGameCard($gameId, 42, 'in_play', $p1); // Imagination
        $courageId = $this->insertGameCard($gameId, 7, 'in_play', $p1); // Courage -- an unrelated card
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $this->pdo->prepare('UPDATE game_cards SET effect_state = :effect_state WHERE id = :id')
            ->execute(['effect_state' => json_encode(['color' => 'green']), 'id' => $imaginationId]);

        $effects = $this->games->getState($gameId, $u1)['round']['board_effects'];

        self::assertCount(1, $effects);
        self::assertSame($imaginationId, $effects[0]['card_id']);
        self::assertSame($p1, $effects[0]['owner_game_player_id']);
        self::assertStringContainsString("boardfx1's Imagination", $effects[0]['description']);
        self::assertStringContainsString('all moods are green', $effects[0]['description']);

        // Scoring effects and board effects are separate lists -- Imagination
        // doesn't touch scoring, so it shouldn't show up there, and Courage
        // (an unrelated in-play card with no color-overriding ability of its
        // own) shouldn't show up in either.
        self::assertSame([], $this->games->getState($gameId, $u1)['round']['scoring_effects']);
    }

    public function testGetStateOmitsImaginationFromBoardEffectsBeforeItsColorIsChosen(): void
    {
        $u1 = $this->insertUser('boardfxpending1');
        $u2 = $this->insertUser('boardfxpending2');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('standard', 'in_progress', :created_by, 3)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $this->insertGamePlayer($gameId, $u2, 1);

        // No effect_state tagged at all -- e.g. a row inserted directly for
        // test setup that never actually went through afterPlaying().
        $this->insertGameCard($gameId, 42, 'in_play', $p1); // Imagination
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        self::assertSame([], $this->games->getState($gameId, $u1)['round']['board_effects']);
    }

    public function testGetStateExposesEachDiscardPileCardsLastKnownOwnerToDisambiguateIdenticalCards(): void
    {
        $u1 = $this->insertUser('discardowner1');
        $u2 = $this->insertUser('discardowner2');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('duel', 'in_progress', :created_by, 3)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $p2 = $this->insertGamePlayer($gameId, $u2, 1);

        // Two different physical Discipline cards (catalog id 9), one
        // discarded from each player's own hand -- exactly the scenario a
        // 'duel' game's two independent decks can produce, per Pacifism's
        // own 'any'-scope target_mood_ids field.
        $p1DisciplineId = $this->insertGameCard($gameId, 9, 'discard', $p1);
        $p2DisciplineId = $this->insertGameCard($gameId, 9, 'discard', $p2);
        // A card the discard pile tracks no owner for at all (e.g. a row
        // predating this feature, or one whose owner was never set).
        $ownerlessId = $this->insertGameCard($gameId, 7, 'discard', null);
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $discardPile = $this->games->getState($gameId, $u1)['discard_pile'];

        $p1Discipline = self::findByCardId($discardPile, $p1DisciplineId);
        self::assertSame($p1, $p1Discipline['last_owner_game_player_id']);
        self::assertSame('discardowner1', $p1Discipline['last_owner_name']);

        $p2Discipline = self::findByCardId($discardPile, $p2DisciplineId);
        self::assertSame($p2, $p2Discipline['last_owner_game_player_id']);
        self::assertSame('discardowner2', $p2Discipline['last_owner_name']);

        $ownerless = self::findByCardId($discardPile, $ownerlessId);
        self::assertNull($ownerless['last_owner_game_player_id']);
        self::assertNull($ownerless['last_owner_name']);
    }

    public function testRecentEventsLetsAPlayerOtherThanTheActorSeeWhatParanoiaRevealed(): void
    {
        $u1 = $this->insertUser('paranoiaevt1');
        $u2 = $this->insertUser('paranoiaevt2');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('standard', 'in_progress', :created_by, 3)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $p2 = $this->insertGamePlayer($gameId, $u2, 1);

        $paranoiaId = $this->insertGameCard($gameId, 71, 'hand', $p1); // Paranoia
        $this->insertGameCard($gameId, 9, 'hand', $p2); // Discipline -- p2's only card, so the reveal is deterministic
        $this->insertGameCard($gameId, 106, 'deck', null, 0); // for Paranoia's own draw
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $this->games->playMood($gameId, $p1, $paranoiaId, ['target_player_id' => $p2]);

        // p2 never submitted this request at all -- without recent_events,
        // they'd have no way to ever learn Discipline was the card
        // revealed from their own hand.
        $events = $this->games->getState($gameId, $u2)['recent_events'];
        self::assertNotEmpty($events);
        self::assertStringContainsString('revealing Discipline', $events[0]['description']);
        self::assertStringContainsString("paranoiaevt2's hand", $events[0]['description']);
    }

    public function testRecentEventsDescribeTheChoiceActuallyMadeForAPlay(): void
    {
        $u1 = $this->insertUser('choiceevt1');
        $u2 = $this->insertUser('choiceevt2');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('standard', 'in_progress', :created_by, 3)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $this->insertGamePlayer($gameId, $u2, 1);

        $encouragementId = $this->insertGameCard($gameId, 11, 'hand', $p1); // Encouragement
        $dignityId = $this->insertGameCard($gameId, 8, 'in_play', $p1); // Dignity (base 3, dice 5) -- the mood targeted
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $this->games->playMood($gameId, $p1, $encouragementId, ['target_mood_id' => $dignityId]);

        $events = $this->games->getState($gameId, $u1)['recent_events'];
        self::assertNotEmpty($events);
        self::assertStringContainsString('target mood: Dignity', $events[0]['description']);
    }

    public function testRoundIncludesPlayGrantsNamingEachOutstandingPlaysSource(): void
    {
        $u1 = $this->insertUser('grantevt1');
        $u2 = $this->insertUser('grantevt2');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('standard', 'in_progress', :created_by, 3)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $this->insertGamePlayer($gameId, $u2, 1);

        $charityId = $this->insertGameCard($gameId, 3, 'hand', $p1); // Charity
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $this->games->playMood($gameId, $p1, $charityId, []);

        $playGrants = $this->games->getState($gameId, $u1)['round']['play_grants'];
        self::assertCount(1, $playGrants); // the base turn's own grant was just consumed playing Charity
        self::assertSame('An extra play from Charity', $playGrants[0]['description']);
        self::assertSame($charityId, $playGrants[0]['source_card_id']);
        self::assertSame('Charity', $playGrants[0]['source_card_name']);
    }

    public function testGetStateExposesEachMoodsPrintedBaseColorAlongsideItsCurrentOne(): void
    {
        $u1 = $this->insertUser('imaginationevt1');
        $u2 = $this->insertUser('imaginationevt2');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('standard', 'in_progress', :created_by, 3)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $this->insertGamePlayer($gameId, $u2, 1);

        $imaginationId = $this->insertGameCard($gameId, 42, 'hand', $p1); // Imagination (blue)
        $charityId = $this->insertGameCard($gameId, 3, 'in_play', $p1); // Charity (white)
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $this->games->playMood($gameId, $p1, $imaginationId, ['color' => 'red']);

        $inPlay = $this->games->getState($gameId, $u1)['in_play'];

        $imagination = self::findByCardId($inPlay, $imaginationId);
        self::assertSame('red', $imagination['color']);
        self::assertSame('blue', $imagination['base_color']);

        $charity = self::findByCardId($inPlay, $charityId);
        self::assertSame('red', $charity['color']);
        self::assertSame('white', $charity['base_color']);
    }

    public function testRecentEventsNameSuspicionsChosenPlayersByUsernameNotId(): void
    {
        $u1 = $this->insertUser('suspicionevt1');
        $u2 = $this->insertUser('suspicionevt2');
        $u3 = $this->insertUser('suspicionevt3');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('standard', 'in_progress', :created_by, 3)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $p2 = $this->insertGamePlayer($gameId, $u2, 1);
        $p3 = $this->insertGamePlayer($gameId, $u3, 2);

        $suspicionId = $this->insertGameCard($gameId, 78, 'hand', $p1); // Suspicion
        $this->insertGameCard($gameId, 9, 'hand', $p2);
        $this->insertGameCard($gameId, 3, 'hand', $p3);
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $this->games->playMood($gameId, $p1, $suspicionId, ['player_ids' => [$p2, $p3]]);

        $events = $this->games->getState($gameId, $u1)['recent_events'];
        self::assertNotEmpty($events);
        // Suspicion's own choice key is the bare 'player_ids' (no leading
        // 'target_'/'opponent_' the way every other card's own player-id
        // choice keys have) -- this used to fall through to raw numeric
        // ids instead of resolving usernames.
        self::assertStringContainsString('suspicionevt2', $events[0]['description']);
        self::assertStringContainsString('suspicionevt3', $events[0]['description']);
    }

    public function testRecentEventsDescribeARoundsFinalScoresAndWinner(): void
    {
        ['gameId' => $gameId, 'p1' => $p1, 'p2' => $p2, 'p3' => $p3, 'u1' => $u1, 'apathyId' => $apathyId] = $this->buildThreePlayerFixture();

        $this->games->playMood($gameId, $p1, $apathyId, []); // Apathy, value 4
        $this->games->pass($gameId, $p2);
        $this->games->pass($gameId, $p3);

        $events = $this->games->getState($gameId, $u1)['recent_events'];
        $roundScored = $events[0]['description'];
        self::assertStringContainsString('p1: 4', $roundScored);
        self::assertStringContainsString('p2: 0', $roundScored);
        self::assertStringContainsString('p3: 0', $roundScored);
        self::assertStringContainsString('p1 won', $roundScored);
    }

    public function testRecentEventsDescribeBravadosMoodMovedFromPlayToDiscard(): void
    {
        $u1 = $this->insertUser('bravadoevt1');
        $this->insertUser('bravadoevt2');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('standard', 'in_progress', :created_by, 3)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);

        $bravadoId = $this->insertGameCard($gameId, 84, 'hand', $p1); // Bravado
        $charityId = $this->insertGameCard($gameId, 3, 'in_play', $p1); // Charity -- discarded as Bravado's own cost
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $this->games->playMood($gameId, $p1, $bravadoId, ['discard_mood_id' => $charityId]);

        $events = $this->games->getState($gameId, $u1)['recent_events'];
        self::assertNotEmpty($events);
        self::assertStringContainsString('mood moved from play to discard: Charity', $events[0]['description']);
    }

    /**
     * Zeal's own draw is never announced by naming the card (see
     * BoardState::$pendingDraws' own docblock -- unlike every other zone
     * move recorded for game history, a card drawn into a hand was never
     * previously public), just that a draw happened at all.
     */
    public function testRecentEventsMentionADrawWithoutNamingTheCard(): void
    {
        $u1 = $this->insertUser('drawevt1');
        $u2 = $this->insertUser('drawevt2');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('standard', 'in_progress', :created_by, 3)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $this->insertGamePlayer($gameId, $u2, 1); // seated so p1's single play doesn't wrap the turn order and auto-score the round

        $zealId = $this->insertGameCard($gameId, 106, 'hand', $p1); // Zeal
        $benevolenceId = $this->insertGameCard($gameId, 2, 'hand', $p1); // Benevolence -- bottomed as Zeal's own cost
        $this->insertGameCard($gameId, 9, 'deck', null, 0); // Discipline -- what actually gets drawn
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $this->games->playMood($gameId, $p1, $zealId, ['hand_card_id' => $benevolenceId]);

        $events = $this->games->getState($gameId, $u1)['recent_events'];
        self::assertNotEmpty($events);
        self::assertStringContainsString('drawevt1 drew a card', $events[0]['description']);
        self::assertStringNotContainsString('Discipline', $events[0]['description']);
    }

    /**
     * Charity's own unconditional grant is announced the moment it's
     * created (source: Charity), separately from -- and in addition to --
     * whatever's eventually said about it once it's actually used (see
     * testRecentEventsMentionWhichExtraPlayGrantWasUsed() below).
     */
    public function testRecentEventsAnnounceANewlyCreatedExtraPlayGrant(): void
    {
        $u1 = $this->insertUser('grantcreate1');
        $u2 = $this->insertUser('grantcreate2');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('standard', 'in_progress', :created_by, 3)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $this->insertGamePlayer($gameId, $u2, 1);

        $charityId = $this->insertGameCard($gameId, 3, 'hand', $p1); // Charity
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $this->games->playMood($gameId, $p1, $charityId, []);

        $events = $this->games->getState($gameId, $u1)['recent_events'];
        self::assertNotEmpty($events);
        self::assertStringContainsString('grantcreate1 was granted an extra play from Charity', $events[0]['description']);
    }

    /**
     * Once that granted play is actually used for a second card, the
     * "played" line for *that* play names the grant it used -- distinct
     * from (and logged well after) the "was granted" line above, which
     * only announces the grant's existence.
     */
    public function testRecentEventsMentionWhichExtraPlayGrantWasUsed(): void
    {
        $u1 = $this->insertUser('grantused1');
        $u2 = $this->insertUser('grantused2');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('standard', 'in_progress', :created_by, 3)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $this->insertGamePlayer($gameId, $u2, 1);

        $charityId = $this->insertGameCard($gameId, 3, 'hand', $p1); // Charity -- grants the extra play
        $apathyId = $this->insertGameCard($gameId, 55, 'hand', $p1); // Apathy -- played using it
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $this->games->playMood($gameId, $p1, $charityId, []);
        $this->games->playMood($gameId, $p1, $apathyId, []);

        $events = $this->games->getState($gameId, $u1)['recent_events'];
        self::assertNotEmpty($events);
        self::assertStringContainsString(
            'grantused1 played Apathy from hand (using an extra play from Charity)',
            $events[0]['description'],
        );
    }

    /** @param array<int, array<string, mixed>> $cards */
    private static function findByCardId(array $cards, int $cardId): array
    {
        foreach ($cards as $card) {
            if ($card['card_id'] === $cardId) {
                return $card;
            }
        }

        self::fail("no card with card_id {$cardId} found");
    }

    /** @param array<int, array<string, mixed>> $fields */
    private static function findFieldByKey(array $fields, string $key): ?array
    {
        foreach ($fields as $field) {
            if ($field['key'] === $key) {
                return $field;
            }
        }

        return null;
    }

    /**
     * Builds a fully deterministic 3-player, wins-needed-2 game by
     * inserting fixture rows directly (bypassing createGame/startGame's
     * shuffle/random first player), so scoring outcomes are predictable.
     * Card ids 55 (Apathy), 83 (Boredom), 5 (Complacency) are vanilla
     * commons with base value 4 and no abilities; 27/54 are just inert
     * deck filler for the post-round draw.
     *
     * @return array{gameId: int, p1: int, p2: int, p3: int}
     */
    private function buildThreePlayerFixture(): array
    {
        $u1 = $this->insertUser('p1');
        $u2 = $this->insertUser('p2');
        $u3 = $this->insertUser('p3');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed)
             VALUES ('standard', 'in_progress', :created_by, 2)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $p2 = $this->insertGamePlayer($gameId, $u2, 1);
        $p3 = $this->insertGamePlayer($gameId, $u3, 2);

        $apathyId = $this->insertGameCard($gameId, 55, 'hand', $p1); // Apathy, value 4
        $boredomId = $this->insertGameCard($gameId, 83, 'hand', $p1); // Boredom, value 4 -- p1's round-2 card
        $complacencyId = $this->insertGameCard($gameId, 5, 'hand', $p2); // Complacency, value 4 -- never played
        $this->insertGameCard($gameId, 27, 'deck', null, 0);
        $this->insertGameCard($gameId, 54, 'deck', null, 1);

        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        return [
            'gameId' => $gameId,
            'p1' => $p1,
            'p2' => $p2,
            'p3' => $p3,
            'u1' => $u1,
            'u2' => $u2,
            'u3' => $u3,
            'apathyId' => $apathyId,
            'boredomId' => $boredomId,
            'complacencyId' => $complacencyId,
        ];
    }

    public function testPlayMoodAdvancesTurnWithoutEndingRoundWhenPlaysRemain(): void
    {
        ['gameId' => $gameId, 'p1' => $p1, 'p2' => $p2, 'apathyId' => $apathyId] = $this->buildThreePlayerFixture();

        $result = $this->games->playMood($gameId, $p1, $apathyId, []);

        self::assertFalse($result['round_scored']);
        self::assertFalse($result['game_completed']);

        $round = $this->fetchRound($gameId);
        self::assertSame($p2, (int) $round['current_turn_game_player_id']);
        self::assertSame(1, (int) $round['plays_remaining']);
    }

    public function testFullRoundCycleAssignsHurtFeelingsDrawsForLosersAndCompletesGame(): void
    {
        [
            'gameId' => $gameId,
            'p1' => $p1,
            'p2' => $p2,
            'p3' => $p3,
            'apathyId' => $apathyId,
            'boredomId' => $boredomId,
        ] = $this->buildThreePlayerFixture();

        // Round 1: p1 plays a mood worth 4, p2 and p3 both pass (score 0).
        $this->games->playMood($gameId, $p1, $apathyId, []);
        $this->games->pass($gameId, $p2);
        $result = $this->games->pass($gameId, $p3);

        self::assertTrue($result['round_scored']);
        self::assertFalse($result['game_completed']);

        $scoreStmt = $this->pdo->prepare(
            'SELECT game_player_id, score FROM game_round_scores gs
             JOIN game_rounds gr ON gr.id = gs.game_round_id
             WHERE gr.game_id = :game_id AND gr.round_number = 1'
        );
        $scoreStmt->execute(['game_id' => $gameId]);
        $scores = [];
        foreach ($scoreStmt->fetchAll() as $row) {
            $scores[(int) $row['game_player_id']] = (int) $row['score'];
        }
        self::assertSame([$p1 => 4, $p2 => 0, $p3 => 0], $scores);

        $round1Stmt = $this->pdo->prepare("SELECT * FROM game_rounds WHERE game_id = :game_id AND round_number = 1");
        $round1Stmt->execute(['game_id' => $gameId]);
        $round1 = $round1Stmt->fetch();
        self::assertSame('scored', $round1['status']);
        self::assertSame($p1, (int) $round1['winner_game_player_id']);

        // Losers p2 and p3 each drew a card from the deck (top-to-bottom: 27 then 54).
        $p2HandStmt = $this->pdo->prepare("SELECT card_id FROM game_cards WHERE game_id = :game_id AND zone = 'hand' AND owner_game_player_id = :owner ORDER BY card_id");
        $p2HandStmt->execute(['game_id' => $gameId, 'owner' => $p2]);
        self::assertSame([5, 27], array_map(intval(...), $p2HandStmt->fetchAll(PDO::FETCH_COLUMN)));

        $p3HandStmt = $this->pdo->prepare("SELECT card_id FROM game_cards WHERE game_id = :game_id AND zone = 'hand' AND owner_game_player_id = :owner");
        $p3HandStmt->execute(['game_id' => $gameId, 'owner' => $p3]);
        self::assertSame([54], array_map(intval(...), $p3HandStmt->fetchAll(PDO::FETCH_COLUMN)));

        // Round 2 begins: p1 (round-1 winner) is first again; Hurt Feelings
        // goes to p3, the tie-break loser who played latest.
        $round2 = $this->fetchRound($gameId);
        self::assertSame(2, (int) $round2['round_number']);
        self::assertSame($p1, (int) $round2['first_game_player_id']);
        self::assertSame($p3, (int) $round2['hurt_feelings_game_player_id']);
        self::assertSame($p1, (int) $round2['current_turn_game_player_id']);

        // p1 plays their remaining card (Boredom, value 4) to win round 2 as well.
        $this->games->playMood($gameId, $p1, $boredomId, []);

        $roundAfterP1 = $this->fetchRound($gameId);
        self::assertSame($p2, (int) $roundAfterP1['current_turn_game_player_id']);

        $this->games->pass($gameId, $p2);

        // It's now p3's turn, and p3 holds Hurt Feelings -- plays_remaining should be 2.
        $roundAtP3 = $this->fetchRound($gameId);
        self::assertSame($p3, (int) $roundAtP3['current_turn_game_player_id']);
        self::assertSame(2, (int) $roundAtP3['plays_remaining']);

        // p3 declines the extra plays and just passes, ending round 2.
        $final = $this->games->pass($gameId, $p3);

        self::assertTrue($final['round_scored']);
        self::assertTrue($final['game_completed']);
        self::assertSame($p1, $final['winner_game_player_id']);

        $game = $this->fetchGame($gameId);
        self::assertSame('completed', $game['status']);
        self::assertSame($p1, (int) $game['winner_game_player_id']);
        self::assertNotNull($game['completed_at']);
    }

    public function testLoserDoesNotDrawACardWhenTheWinningRoundEndsTheGame(): void
    {
        $u1 = $this->insertUser('finalround-p1');
        $u2 = $this->insertUser('finalround-p2');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed)
             VALUES ('standard', 'in_progress', :created_by, 1)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $p2 = $this->insertGamePlayer($gameId, $u2, 1);

        $apathyId = $this->insertGameCard($gameId, 55, 'hand', $p1); // Apathy, value 4
        $this->insertGameCard($gameId, 27, 'deck', null, 0); // would be p2's draw if the bug were present

        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $this->games->playMood($gameId, $p1, $apathyId, []);
        $result = $this->games->pass($gameId, $p2);

        self::assertTrue($result['round_scored']);
        self::assertTrue($result['game_completed']);
        self::assertSame($p1, $result['winner_game_player_id']);

        // p2 lost the round that ended the game -- there's no next round
        // for a "loser draws a card" bonus to matter in, so p2's hand must
        // stay empty and the deck untouched.
        $p2HandStmt = $this->pdo->prepare("SELECT COUNT(*) FROM game_cards WHERE game_id = :game_id AND zone = 'hand' AND owner_game_player_id = :owner");
        $p2HandStmt->execute(['game_id' => $gameId, 'owner' => $p2]);
        self::assertSame(0, (int) $p2HandStmt->fetchColumn());

        $deckStmt = $this->pdo->prepare("SELECT card_id FROM game_cards WHERE game_id = :game_id AND zone = 'deck'");
        $deckStmt->execute(['game_id' => $gameId]);
        self::assertSame([27], array_map(intval(...), $deckStmt->fetchAll(PDO::FETCH_COLUMN)));
    }

    public function testPassOutOfTurnIsRejected(): void
    {
        ['gameId' => $gameId, 'p2' => $p2] = $this->buildThreePlayerFixture();

        $this->expectException(GameStateException::class);
        $this->games->pass($gameId, $p2);
    }

    public function testPlayingOutOfTurnRaisesRulesLevelException(): void
    {
        ['gameId' => $gameId, 'p2' => $p2, 'complacencyId' => $complacencyId] = $this->buildThreePlayerFixture();

        $this->expectException(IllegalPlayException::class);
        $this->games->playMood($gameId, $p2, $complacencyId, []);
    }

    /**
     * A restricted extra-play grant (e.g. Benevolence's "if it doesn't
     * share a color with any of your moods") has to survive a full
     * load()/save() round trip through game_rounds.pending_play_grants,
     * since each play is its own request with no BoardState kept alive in
     * memory between them -- this proves the restriction is still
     * enforced after being persisted and reloaded, not just within a
     * single in-memory BoardState (already covered by the Rules tests).
     *
     * @return array{gameId: int, p1: int}
     */
    private function buildBenevolenceFixture(): array
    {
        $u1 = $this->insertUser('bene1');
        $u2 = $this->insertUser('bene2');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('standard', 'in_progress', :created_by, 3)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $this->insertGamePlayer($gameId, $u2, 1);

        $benevolenceId = $this->insertGameCard($gameId, 2, 'hand', $p1); // Benevolence, white
        $dignityId = $this->insertGameCard($gameId, 8, 'hand', $p1); // Dignity, white -- shares Benevolence's color
        $zealId = $this->insertGameCard($gameId, 106, 'hand', $p1); // Zeal, red -- doesn't share
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        return [
            'gameId' => $gameId,
            'p1' => $p1,
            'benevolenceId' => $benevolenceId,
            'dignityId' => $dignityId,
            'zealId' => $zealId,
        ];
    }

    public function testRestrictedExtraPlayGrantIsEnforcedAfterReloadingFromTheDatabase(): void
    {
        [
            'gameId' => $gameId,
            'p1' => $p1,
            'benevolenceId' => $benevolenceId,
            'dignityId' => $dignityId,
        ] = $this->buildBenevolenceFixture();

        $this->games->playMood($gameId, $p1, $benevolenceId, []); // Benevolence -- grants a restricted extra play

        $round = $this->fetchRound($gameId);
        self::assertSame(1, (int) $round['plays_remaining']);
        self::assertNotNull($round['pending_play_grants']);

        // A fresh load() from the database (this is a brand new call, not
        // reusing any in-memory state from the play above) must still
        // reject Dignity as sharing Benevolence's color.
        $this->expectException(IllegalPlayException::class);
        $this->games->playMood($gameId, $p1, $dignityId, []);
    }

    public function testRestrictedExtraPlayGrantAllowsAQualifyingCardAfterReload(): void
    {
        [
            'gameId' => $gameId,
            'p1' => $p1,
            'benevolenceId' => $benevolenceId,
            'zealId' => $zealId,
        ] = $this->buildBenevolenceFixture();

        $this->games->playMood($gameId, $p1, $benevolenceId, []); // Benevolence -- grants a restricted extra play
        $this->games->playMood($gameId, $p1, $zealId, []); // Zeal, red -- doesn't share Benevolence's color

        $zoneStmt = $this->pdo->prepare('SELECT zone FROM game_cards WHERE game_id = :game_id AND card_id = 106');
        $zoneStmt->execute(['game_id' => $gameId]);
        self::assertSame('in_play', $zoneStmt->fetchColumn());
    }

    /**
     * Chivalry ("value is 5 if you didn't go first this round") reads
     * game_rounds.first_game_player_id via BoardState::roundFirstPlayerId(),
     * which -- like the restricted play grants above -- has to survive a
     * database reload rather than living only in an in-memory BoardState.
     */
    public function testChivalryValueReflectsTheRoundsFirstPlayerAfterReload(): void
    {
        $u1 = $this->insertUser('chiv1');
        $u2 = $this->insertUser('chiv2');
        $u3 = $this->insertUser('chiv3');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('standard', 'in_progress', :created_by, 3)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $p2 = $this->insertGamePlayer($gameId, $u2, 1);
        $this->insertGamePlayer($gameId, $u3, 2);

        $chivalryId = $this->insertGameCard($gameId, 4, 'hand', $p2); // Chivalry
        // p1 went first; it's now p2's turn -- p2 is a middle turn (not
        // the round's last), so the round stays in progress and
        // first_game_player_id isn't disturbed by this play.
        $this->insertGameRound($gameId, 1, $p1, $p2, 1);

        $this->games->playMood($gameId, $p2, $chivalryId, []);

        $registry = DefaultEffectRegistry::build();
        $state = (new BoardStateRepository($registry))->load($gameId);
        self::assertSame(5, $state->valueOf($chivalryId)); // p2 didn't go first this round
    }

    /**
     * Honor's "the chosen player goes first each round regardless of who
     * won" has to survive the same load()/save() round trip as everything
     * else, and GameService has to actually consult it (instead of the
     * round winner) when starting the next round.
     */
    public function testHonorOverridesWhoGoesFirstNextRoundInsteadOfTheWinner(): void
    {
        $u1 = $this->insertUser('honor1');
        $u2 = $this->insertUser('honor2');
        $u3 = $this->insertUser('honor3');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('standard', 'in_progress', :created_by, 2)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $p2 = $this->insertGamePlayer($gameId, $u2, 1);
        $p3 = $this->insertGamePlayer($gameId, $u3, 2);

        $honorId = $this->insertGameCard($gameId, 15, 'hand', $p1); // Honor, value 3
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        // p1 plays Honor naming p3, then wins round 1 outright (3 vs 0/0).
        $this->games->playMood($gameId, $p1, $honorId, ['target_player_id' => $p3]);
        $this->games->pass($gameId, $p2);
        $result = $this->games->pass($gameId, $p3);

        self::assertTrue($result['round_scored']);
        self::assertFalse($result['game_completed']);

        $round1Stmt = $this->pdo->prepare('SELECT winner_game_player_id FROM game_rounds WHERE game_id = :game_id AND round_number = 1');
        $round1Stmt->execute(['game_id' => $gameId]);
        self::assertSame($p1, (int) $round1Stmt->fetchColumn());

        $round2 = $this->fetchRound($gameId);
        self::assertSame(2, (int) $round2['round_number']);
        self::assertSame($p3, (int) $round2['first_game_player_id']); // Honor's override, not the winner (p1)
    }

    /**
     * Sneakiness's score swap has to actually change who wins, so it must
     * be applied before RoundScorer::winner() runs -- not just recorded
     * for information after the fact.
     */
    public function testSneakinessSwapsScoresBeforeDeterminingTheWinner(): void
    {
        $u1 = $this->insertUser('sneak1');
        $u2 = $this->insertUser('sneak2');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('standard', 'in_progress', :created_by, 3)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $p2 = $this->insertGamePlayer($gameId, $u2, 1);

        $sneakinessId = $this->insertGameCard($gameId, 51, 'hand', $p1); // Sneakiness, value 5
        $this->insertGameCard($gameId, 120, 'in_play', $p2); // Generosity, value 6 -- already ahead
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        // Before the swap, p2 leads 6 to 5; after it, p1 should win 6 to 5.
        $this->games->playMood($gameId, $p1, $sneakinessId, ['opponent_player_id' => $p2]);
        $result = $this->games->pass($gameId, $p2);

        self::assertTrue($result['round_scored']);

        $round1Stmt = $this->pdo->prepare('SELECT winner_game_player_id FROM game_rounds WHERE game_id = :game_id AND round_number = 1');
        $round1Stmt->execute(['game_id' => $gameId]);
        self::assertSame($p1, (int) $round1Stmt->fetchColumn());

        $scoresStmt = $this->pdo->prepare(
            "SELECT s.game_player_id, s.score FROM game_round_scores s
             JOIN game_rounds r ON r.id = s.game_round_id
             WHERE r.game_id = :game_id AND r.round_number = 1"
        );
        $scoresStmt->execute(['game_id' => $gameId]);
        $scores = array_column($scoresStmt->fetchAll(), 'score', 'game_player_id');
        self::assertSame(6, (int) $scores[$p1]);
        self::assertSame(5, (int) $scores[$p2]);
    }

    /**
     * Awe skips scoring entirely: no winner, no scores recorded, no one
     * draws, and next round's first player comes from Awe's own
     * one-time override rather than the (nonexistent) round winner.
     */
    public function testAweSkipsScoringAndSetsNextRoundsFirstPlayer(): void
    {
        $u1 = $this->insertUser('awe1');
        $u2 = $this->insertUser('awe2');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('standard', 'in_progress', :created_by, 3)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $p2 = $this->insertGamePlayer($gameId, $u2, 1);

        $aweId = $this->insertGameCard($gameId, 107, 'hand', $p1); // Awe
        $this->insertGameCard($gameId, 3, 'deck', null, 0); // would be drawn if scoring weren't skipped
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $this->games->playMood($gameId, $p1, $aweId, ['target_player_id' => $p2]);
        $result = $this->games->pass($gameId, $p2);

        self::assertTrue($result['round_scored']);
        self::assertFalse($result['game_completed']);

        $round1Stmt = $this->pdo->prepare('SELECT status, winner_game_player_id FROM game_rounds WHERE game_id = :game_id AND round_number = 1');
        $round1Stmt->execute(['game_id' => $gameId]);
        $round1 = $round1Stmt->fetch();
        self::assertSame('scored', $round1['status']);
        self::assertNull($round1['winner_game_player_id']);

        $scoresStmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM game_round_scores s JOIN game_rounds r ON r.id = s.game_round_id
             WHERE r.game_id = :game_id AND r.round_number = 1"
        );
        $scoresStmt->execute(['game_id' => $gameId]);
        self::assertSame(0, (int) $scoresStmt->fetchColumn());

        // No one drew a card -- the deck filler is still sitting in the deck.
        $deckCardStmt = $this->pdo->prepare("SELECT zone FROM game_cards WHERE game_id = :game_id AND card_id = 3");
        $deckCardStmt->execute(['game_id' => $gameId]);
        self::assertSame('deck', $deckCardStmt->fetchColumn());

        $round2 = $this->fetchRound($gameId);
        self::assertSame(2, (int) $round2['round_number']);
        self::assertSame($p2, (int) $round2['first_game_player_id']);
        self::assertSame($p2, (int) $round2['current_turn_game_player_id']);
    }

    /**
     * Bashfulness's after-scoring hook has to survive a real load()/save()
     * round trip: it's tagged when played, then resolved once the round's
     * winner is known, moving itself to the bottom of the deck and
     * drawing its owner a replacement -- distinct from the loser's draw,
     * which happens to every non-winner regardless of any card effect.
     */
    public function testBashfulnessMovesToBottomOfDeckAndDrawsWhenItsOwnerWinsTheRound(): void
    {
        $u1 = $this->insertUser('bash1');
        $u2 = $this->insertUser('bash2');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('standard', 'in_progress', :created_by, 3)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $p2 = $this->insertGamePlayer($gameId, $u2, 1);

        $bashfulnessId = $this->insertGameCard($gameId, 30, 'hand', $p1); // Bashfulness, value 6
        $p2DrawId = $this->insertGameCard($gameId, 3, 'deck', null, 0); // p2's loser draw
        $p1ReplacementId = $this->insertGameCard($gameId, 7, 'deck', null, 1); // p1's replacement draw
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $this->games->playMood($gameId, $p1, $bashfulnessId, []);
        $result = $this->games->pass($gameId, $p2);

        self::assertTrue($result['round_scored']);

        $registry = DefaultEffectRegistry::build();
        $state = (new BoardStateRepository($registry))->load($gameId);

        self::assertFalse($state->isInPlay($bashfulnessId));
        self::assertContains($p1ReplacementId, $state->hand($p1));
        self::assertContains($p2DrawId, $state->hand($p2));
        self::assertSame([$bashfulnessId], $state->deck());
    }

    /**
     * Bashfulness's own after-scoring move (still in play, so already
     * public) belongs in the round_scored event's own history same as any
     * other zone move -- but the replacement card its owner draws right
     * afterward must NOT appear anywhere, since a drawn card was never
     * public the way an in-play one was (see BoardState::drawCard()'s own
     * docblock for why that one move is deliberately never recorded).
     */
    public function testRecentEventsDescribeBashfulnessesAfterScoringMoveButNotTheReplacementDraw(): void
    {
        $u1 = $this->insertUser('bashevt1');
        $u2 = $this->insertUser('bashevt2');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('standard', 'in_progress', :created_by, 3)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $p2 = $this->insertGamePlayer($gameId, $u2, 1);

        $bashfulnessId = $this->insertGameCard($gameId, 30, 'hand', $p1); // Bashfulness, value 6
        $this->insertGameCard($gameId, 3, 'deck', null, 0); // p2's loser draw
        $this->insertGameCard($gameId, 7, 'deck', null, 1); // p1's replacement draw (Courage) -- must stay unnamed
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $this->games->playMood($gameId, $p1, $bashfulnessId, []);
        $this->games->pass($gameId, $p2);

        $events = $this->games->getState($gameId, $u1)['recent_events'];
        $roundScored = $events[0]['description'];
        self::assertStringContainsString('Bashfulness moved from play to the deck', $roundScored);
        self::assertStringNotContainsString('Courage', $roundScored);
    }

    /**
     * Corruption's "the winner of the current round wins two rounds
     * instead of one" has to actually move game_rounds.wins_awarded, and
     * totalWinsFor() has to pick that up when checking for game
     * completion -- here a single round is enough to finish a
     * wins-needed-2 game instead of the usual two.
     */
    public function testCorruptionsDoubleWinCompletesTheGameAfterOneRound(): void
    {
        $u1 = $this->insertUser('corrupt1');
        $u2 = $this->insertUser('corrupt2');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('standard', 'in_progress', :created_by, 2)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $p2 = $this->insertGamePlayer($gameId, $u2, 1);

        $corruptionId = $this->insertGameCard($gameId, 60, 'hand', $p1); // Corruption, value 2
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $this->games->playMood($gameId, $p1, $corruptionId, ['mode' => 'double_win']);
        $result = $this->games->pass($gameId, $p2);

        self::assertTrue($result['round_scored']);
        self::assertTrue($result['game_completed']);
        self::assertSame($p1, $result['winner_game_player_id']);

        $roundStmt = $this->pdo->prepare('SELECT wins_awarded FROM game_rounds WHERE game_id = :game_id AND round_number = 1');
        $roundStmt->execute(['game_id' => $gameId]);
        self::assertSame(2, (int) $roundStmt->fetchColumn());

        self::assertSame('completed', $this->fetchGame($gameId)['status']);
    }

    /**
     * Doubt's next-round color ban has to survive a real load()/save()
     * round trip: tagged when played (round 1), inert during that same
     * round, then enforced during round 2 -- rejecting a matching-color
     * play even though it would otherwise be perfectly legal.
     */
    public function testDoubtBansAColorForTheFollowingRoundOnlyAfterReload(): void
    {
        $u1 = $this->insertUser('doubt1');
        $u2 = $this->insertUser('doubt2');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('standard', 'in_progress', :created_by, 3)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $p2 = $this->insertGamePlayer($gameId, $u2, 1);

        $doubtId = $this->insertGameCard($gameId, 36, 'hand', $p1); // Doubt
        $dignityId = $this->insertGameCard($gameId, 8, 'hand', $p1); // Dignity, white -- revealed
        $courageId = $this->insertGameCard($gameId, 7, 'hand', $p2); // Courage, white -- would be banned next round
        $this->insertGameCard($gameId, 55, 'deck', null, 0); // p1's replacement draw
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $this->games->playMood($gameId, $p1, $doubtId, ['reveal_card_ids' => [$dignityId]]);
        $this->games->pass($gameId, $p2);

        $round2 = $this->fetchRound($gameId);
        self::assertSame(2, (int) $round2['round_number']);
        self::assertSame($p1, (int) $round2['current_turn_game_player_id']); // p1 won round 1

        $this->games->pass($gameId, $p1); // advance to p2's turn without ending round 2

        $this->expectException(IllegalPlayException::class);
        $this->games->playMood($gameId, $p2, $courageId, []);
    }

    /**
     * Hope's "every turn while in play" grant is computed fresh by
     * GameService at the start of each turn, not stored anywhere on
     * Hope's own mood -- so this only proves it's actually wired up if a
     * *later* turn (after a real load()/save() round trip and a full
     * round boundary) still reflects it.
     */
    public function testHopePersistsAPerpetualExtraPlayIntoFutureTurnsAfterReload(): void
    {
        $u1 = $this->insertUser('hope1');
        $u2 = $this->insertUser('hope2');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('standard', 'in_progress', :created_by, 3)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $p2 = $this->insertGamePlayer($gameId, $u2, 1);

        $hopeId = $this->insertGameCard($gameId, 124, 'hand', $p1); // Hope, value 0
        $apathyId = $this->insertGameCard($gameId, 55, 'hand', $p1); // Apathy, value 4 -- played with Hope's same-turn bonus
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $this->games->playMood($gameId, $p1, $hopeId, []);
        $this->games->playMood($gameId, $p1, $apathyId, []);
        $this->games->pass($gameId, $p2);

        $round2 = $this->fetchRound($gameId);
        self::assertSame(2, (int) $round2['round_number']);
        self::assertSame($p1, (int) $round2['first_game_player_id']); // p1 won round 1 (4 to 0)
        self::assertSame(2, (int) $round2['plays_remaining']); // base 1 + Hope's perpetual bonus
    }

    /**
     * Stubbornness's bonus depends on live mood counts checked fresh at
     * the start of each turn -- here p2's two pre-seeded moods outnumber
     * p1's one (Stubbornness itself) by the time round 2 starts, so p1's
     * first turn of round 2 should include the bonus.
     */
    public function testStubbornnessGrantsAnExtraPlayWhenAnotherPlayerHasMoreMoodsAfterReload(): void
    {
        $u1 = $this->insertUser('stub1');
        $u2 = $this->insertUser('stub2');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('standard', 'in_progress', :created_by, 3)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $p2 = $this->insertGamePlayer($gameId, $u2, 1);

        $stubbornnessId = $this->insertGameCard($gameId, 102, 'hand', $p1); // Stubbornness, value 3
        $this->insertGameCard($gameId, 66, 'in_play', $p2); // Hate, value 0
        $this->insertGameCard($gameId, 105, 'in_play', $p2); // Wrath, value 0 -- p2 now has 2 moods
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $this->games->playMood($gameId, $p1, $stubbornnessId, []);
        $this->games->pass($gameId, $p2);

        $round2 = $this->fetchRound($gameId);
        self::assertSame(2, (int) $round2['round_number']);
        self::assertSame($p1, (int) $round2['first_game_player_id']); // p1 won round 1 (3 to 0)
        self::assertSame(2, (int) $round2['plays_remaining']); // base 1 + Stubbornness's bonus (p2 has more moods)
    }

    /**
     * The negative case for the test above: "if another player has MORE
     * moods than you" is a strict comparison, so an opponent with an equal
     * or lower mood count grants nothing.
     */
    public function testStubbornnessGrantsNoBonusWhenNoOtherPlayerHasMoreMoods(): void
    {
        $u1 = $this->insertUser('stub3');
        $u2 = $this->insertUser('stub4');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('standard', 'in_progress', :created_by, 3)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $p2 = $this->insertGamePlayer($gameId, $u2, 1);

        $stubbornnessId = $this->insertGameCard($gameId, 102, 'hand', $p1); // Stubbornness, value 3
        $this->insertGameCard($gameId, 66, 'in_play', $p1); // Hate, value 0 -- p1 already has 1 mood before playing Stubbornness
        $this->insertGameCard($gameId, 105, 'in_play', $p2); // Wrath, value 0 -- p2 has exactly as many moods as p1 will, not more
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $this->games->playMood($gameId, $p1, $stubbornnessId, []); // p1 now has 2 moods (Hate + Stubbornness) vs p2's 1
        $this->games->pass($gameId, $p2);

        $round2 = $this->fetchRound($gameId);
        self::assertSame(2, (int) $round2['round_number']);
        self::assertSame($p1, (int) $round2['first_game_player_id']); // p1 won round 1 (3 to 0)
        self::assertSame(1, (int) $round2['plays_remaining']); // base 1 only -- no opponent has more moods than p1
    }

    public function testGenerosityBanksAnExtraPlayForTheChosenPlayersNextTurn(): void
    {
        $u1 = $this->insertUser('gen1');
        $u2 = $this->insertUser('gen2');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('standard', 'in_progress', :created_by, 3)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $p2 = $this->insertGamePlayer($gameId, $u2, 1);

        $generosityId = $this->insertGameCard($gameId, 120, 'hand', $p1); // Generosity
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $this->games->playMood($gameId, $p1, $generosityId, ['target_player_id' => $p2]);

        $round = $this->fetchRound($gameId);
        self::assertSame($p2, (int) $round['current_turn_game_player_id']);
        self::assertSame(2, (int) $round['plays_remaining']); // base 1 + Generosity's banked play
    }

    /**
     * Joy banks its bonus for the acting player's *own* next turn, which
     * (in a 2-player game) is the very first turn of the next round --
     * proving it survives not just a turn boundary but a full round
     * boundary (scoring, a new game_rounds row, reload) too.
     */
    public function testJoyBanksAnExtraPlayForYourOwnNextTurnAcrossARoundBoundary(): void
    {
        $u1 = $this->insertUser('joy1');
        $u2 = $this->insertUser('joy2');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('standard', 'in_progress', :created_by, 3)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $p2 = $this->insertGamePlayer($gameId, $u2, 1);

        $joyId = $this->insertGameCard($gameId, 125, 'hand', $p1); // Joy, value 3
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $this->games->playMood($gameId, $p1, $joyId, []);
        $this->games->pass($gameId, $p2);

        $round2 = $this->fetchRound($gameId);
        self::assertSame(2, (int) $round2['round_number']);
        self::assertSame($p1, (int) $round2['first_game_player_id']); // p1 won round 1 (3 to 0)
        self::assertSame(2, (int) $round2['plays_remaining']); // base 1 + Joy's banked play
    }

    /**
     * Arrogance's "give it back if you still have it" only triggers once
     * the mood actually leaves play -- exercised here via a direct
     * BoardStateRepository round trip, since no player action in this
     * batch discards one's own already-in-play mood generically.
     */
    public function testArroganceReturnsTheTakenMoodAfterReloadWhenItLeavesPlay(): void
    {
        $u1 = $this->insertUser('arr1');
        $u2 = $this->insertUser('arr2');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('standard', 'in_progress', :created_by, 3)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $p2 = $this->insertGamePlayer($gameId, $u2, 1);

        $arroganceId = $this->insertGameCard($gameId, 82, 'hand', $p1); // Arrogance
        $dignityId = $this->insertGameCard($gameId, 8, 'in_play', $p2); // Dignity, white
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $playResult = $this->games->playMood($gameId, $p1, $arroganceId, ['opponent_player_id' => $p2]);
        self::assertTrue($playResult['pending_decision'] ?? false);
        $this->games->respondToDecision($gameId, $p2, ['chosen_mood_id' => $dignityId]);

        $registry = DefaultEffectRegistry::build();
        $repository = new BoardStateRepository($registry);
        $state = $repository->load($gameId);
        self::assertSame($p1, $state->ownerOf($dignityId));

        $state->moveInPlayToDiscard($arroganceId);
        $repository->save($gameId, $state);

        $reloaded = $repository->load($gameId);
        self::assertSame($p2, $reloaded->ownerOf($dignityId));
        self::assertFalse($reloaded->isInPlay($arroganceId));
    }

    /**
     * Scorn's reaction has to keep working on a mood that was played (and
     * saved/reloaded) in an *earlier* round -- it isn't tied to the turn
     * or round Scorn itself was played in, just to its owner's
     * subsequent plays, however much later those happen.
     */
    public function testScornReactsToASubsequentPlayInALaterRoundAfterReload(): void
    {
        $u1 = $this->insertUser('scorn1');
        $u2 = $this->insertUser('scorn2');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('standard', 'in_progress', :created_by, 3)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $p2 = $this->insertGamePlayer($gameId, $u2, 1);

        $charityId = $this->insertGameCard($gameId, 3, 'hand', $p1); // Charity -- grants the extra play needed to also play Scorn this turn
        $scornId = $this->insertGameCard($gameId, 24, 'hand', $p1); // Scorn, value 2, white
        $courageId = $this->insertGameCard($gameId, 7, 'hand', $p1); // Courage, white -- played next round to trigger the reaction
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $this->games->playMood($gameId, $p1, $charityId, []);
        $this->games->playMood($gameId, $p1, $scornId, ['target_mood_id' => $charityId]); // Scorn suppresses Charity
        $this->games->pass($gameId, $p2);

        $round2 = $this->fetchRound($gameId);
        self::assertSame(2, (int) $round2['round_number']);
        self::assertSame($p1, (int) $round2['first_game_player_id']); // p1 won round 1 (2 to 0)

        $this->games->playMood($gameId, $p1, $courageId, ['scorn_suppress_target' => $scornId]); // Courage (white) reacts, suppressing Scorn itself

        $registry = DefaultEffectRegistry::build();
        $state = (new BoardStateRepository($registry))->load($gameId);
        self::assertTrue($state->isSuppressed($scornId));
    }

    public function testCompulsionPausesForP2sOwnChoiceAndOnlyCompletesAfterTheyRespond(): void
    {
        $u1 = $this->insertUser('comp1');
        $u2 = $this->insertUser('comp2');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('standard', 'in_progress', :created_by, 3)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $p2 = $this->insertGamePlayer($gameId, $u2, 1);

        $compulsionId = $this->insertGameCard($gameId, 86, 'hand', $p1); // Compulsion
        $card3Id = $this->insertGameCard($gameId, 3, 'hand', $p2);
        $card7Id = $this->insertGameCard($gameId, 7, 'hand', $p2);
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $playResult = $this->games->playMood($gameId, $p1, $compulsionId, ['target_player_id' => $p2]);
        self::assertTrue($playResult['pending_decision'] ?? false);

        $registry = DefaultEffectRegistry::build();
        $state = (new BoardStateRepository($registry))->load($gameId);
        self::assertTrue($state->isInPlay($compulsionId)); // cost/grant already resolved -- only the target's answer is outstanding
        self::assertSame([$card3Id, $card7Id], $state->hand($p2));

        // The whole round is frozen -- not even the acting player can pass.
        try {
            $this->games->pass($gameId, $p1);
            self::fail('Expected passing while a decision is pending to be rejected');
        } catch (GameStateException) {
            // expected
        }

        $respondResult = $this->games->respondToDecision($gameId, $p2, ['given_card_id' => $card3Id]);
        self::assertArrayNotHasKey('pending_decision', $respondResult);

        $state = (new BoardStateRepository($registry))->load($gameId);
        $p1Hand = $state->hand($p1);
        $p2Hand = $state->hand($p2);
        self::assertSame([$card3Id], $p1Hand);
        self::assertSame([$card7Id], $p2Hand);
    }

    public function testRespondToDecisionRejectsAPlayerWithNoDecisionPending(): void
    {
        $u1 = $this->insertUser('nodecision1');
        $u2 = $this->insertUser('nodecision2');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('standard', 'in_progress', :created_by, 3)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $p2 = $this->insertGamePlayer($gameId, $u2, 1);

        $this->insertGameCard($gameId, 3, 'hand', $p1); // Charity, no decision needed
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $this->expectException(GameStateException::class);
        $this->games->respondToDecision($gameId, $p2, ['given_card_id' => 1]);
    }

    public function testGetStateExposesThePendingDecisionsFieldOnlyToItsTargetNotBystanders(): void
    {
        $u1 = $this->insertUser('pendingstate1');
        $u2 = $this->insertUser('pendingstate2');
        $u3 = $this->insertUser('pendingstate3');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('standard', 'in_progress', :created_by, 3)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $p2 = $this->insertGamePlayer($gameId, $u2, 1);
        $this->insertGamePlayer($gameId, $u3, 2);

        $compulsionId = $this->insertGameCard($gameId, 86, 'hand', $p1); // Compulsion
        $this->insertGameCard($gameId, 3, 'hand', $p2);
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $beforePlay = $this->games->getState($gameId, $u1)['round']['pending_decision'];
        self::assertNull($beforePlay);

        $this->games->playMood($gameId, $p1, $compulsionId, ['target_player_id' => $p2]);

        $targetView = $this->games->getState($gameId, $u2)['round']['pending_decision'];
        self::assertSame($p1, $targetView['initiating_game_player_id']);
        self::assertSame($compulsionId, $targetView['played_card_id']);
        self::assertSame('Compulsion', $targetView['played_card_name']);
        self::assertSame('compulsion_give_card', $targetView['decision_type']);
        self::assertSame($p2, $targetView['target_game_player_id']);
        self::assertTrue($targetView['is_you']);
        self::assertSame('hand_card', $targetView['field']['type']);

        $initiatorView = $this->games->getState($gameId, $u1)['round']['pending_decision'];
        self::assertFalse($initiatorView['is_you']);
        self::assertArrayNotHasKey('field', $initiatorView);

        $bystanderView = $this->games->getState($gameId, $u3)['round']['pending_decision'];
        self::assertFalse($bystanderView['is_you']);
        self::assertArrayNotHasKey('field', $bystanderView);
    }

    public function testIntimidationsGrantSurvivesReloadAndOnlyAllowsTheRevealedCard(): void
    {
        $u1 = $this->insertUser('intim1');
        $u2 = $this->insertUser('intim2');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('standard', 'in_progress', :created_by, 3)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $p2 = $this->insertGamePlayer($gameId, $u2, 1);

        $intimidationId = $this->insertGameCard($gameId, 67, 'hand', $p1); // Intimidation
        $complacencyId = $this->insertGameCard($gameId, 5, 'hand', $p1); // Complacency -- not the revealed card
        $card3Id = $this->insertGameCard($gameId, 3, 'hand', $p2); // p2's only card -- guaranteed to be the one revealed
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $playResult = $this->games->playMood($gameId, $p1, $intimidationId, ['target_player_id' => $p2]);
        self::assertTrue($playResult['pending_decision'] ?? false);
        $this->games->respondToDecision($gameId, $p2, ['revealed_card_id' => $card3Id]);

        $this->expectException(IllegalPlayException::class);
        $this->games->playMood($gameId, $p1, $complacencyId, []);
    }

    public function testInstabilityPausesForP2sOwnChoiceThenP1sOwnChoiceAndOnlyCompletesAfterBothRespond(): void
    {
        $u1 = $this->insertUser('instab1');
        $u2 = $this->insertUser('instab2');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('standard', 'in_progress', :created_by, 3)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $p2 = $this->insertGamePlayer($gameId, $u2, 1);

        $instabilityId = $this->insertGameCard($gameId, 96, 'hand', $p1); // Instability
        $givenId = $this->insertGameCard($gameId, 9, 'in_play', $p1); // given in exchange
        $candidate3Id = $this->insertGameCard($gameId, 3, 'in_play', $p2); // candidate
        $candidate7Id = $this->insertGameCard($gameId, 7, 'in_play', $p2); // candidate
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        // given_mood_id is no longer submitted up front -- it can't be
        // offered as an ordinary choice_fields entry, since Instability
        // itself isn't in play yet at the moment this request is built;
        // see testInstabilityCanGiveItselfAwayThroughARealRoundTrip()
        // below.
        $playResult = $this->games->playMood($gameId, $p1, $instabilityId, [
            'candidate_mood_ids' => [$candidate3Id, $candidate7Id],
        ]);
        self::assertTrue($playResult['pending_decision'] ?? false);

        $registry = DefaultEffectRegistry::build();
        $state = (new BoardStateRepository($registry))->load($gameId);
        self::assertSame($p2, $state->ownerOf($candidate3Id)); // not taken yet
        self::assertSame($p2, $state->ownerOf($candidate7Id));

        // p1 can't answer their own "what do I give back" step before p2
        // has answered the "which do I give up" step first.
        try {
            $this->games->respondToDecision($gameId, $p1, ['given_mood_id' => $givenId]);
            self::fail('Expected p1 to have no decision pending until p2 answers first');
        } catch (GameStateException) {
            // expected
        }

        $firstRespondResult = $this->games->respondToDecision($gameId, $p2, ['taken_mood_id' => $candidate7Id]);
        self::assertTrue($firstRespondResult['pending_decision'] ?? false);

        $state = (new BoardStateRepository($registry))->load($gameId);
        self::assertSame($p2, $state->ownerOf($candidate7Id)); // still p2's until p1 answers too

        $finalRespondResult = $this->games->respondToDecision($gameId, $p1, ['given_mood_id' => $givenId]);
        self::assertArrayNotHasKey('pending_decision', $finalRespondResult);

        $state = (new BoardStateRepository($registry))->load($gameId);
        self::assertSame($p1, $state->ownerOf($candidate7Id));
        self::assertSame($p2, $state->ownerOf($candidate3Id)); // the other candidate is untouched
        self::assertSame($p2, $state->ownerOf($givenId));
    }

    /**
     * The whole point of deferring given_mood_id until after Instability
     * has actually entered play: giving Instability itself away is a
     * legal answer, even though it could never have been offered as an
     * ordinary up-front choice.
     */
    public function testInstabilityCanGiveItselfAwayThroughARealRoundTrip(): void
    {
        $u1 = $this->insertUser('instabself1');
        $u2 = $this->insertUser('instabself2');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('standard', 'in_progress', :created_by, 3)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $p2 = $this->insertGamePlayer($gameId, $u2, 1);

        $instabilityId = $this->insertGameCard($gameId, 96, 'hand', $p1); // Instability
        $candidate3Id = $this->insertGameCard($gameId, 3, 'in_play', $p2);
        $candidate7Id = $this->insertGameCard($gameId, 7, 'in_play', $p2);
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $this->games->playMood($gameId, $p1, $instabilityId, [
            'candidate_mood_ids' => [$candidate3Id, $candidate7Id],
        ]);
        $this->games->respondToDecision($gameId, $p2, ['taken_mood_id' => $candidate7Id]);
        $this->games->respondToDecision($gameId, $p1, ['given_mood_id' => $instabilityId]);

        $state = (new BoardStateRepository(DefaultEffectRegistry::build()))->load($gameId);
        self::assertSame($p1, $state->ownerOf($candidate7Id));
        self::assertSame($p2, $state->ownerOf($instabilityId));
        self::assertTrue($state->isInPlay($instabilityId));
    }

    /**
     * Suspicion's queue has two independent targets -- the round has to
     * stay frozen after the first one answers (p3 hasn't gone yet) and
     * only actually finish once the last one in the queue responds.
     */
    public function testSuspicionQueuesEachChosenPlayerAndOnlyCompletesAfterTheLastResponds(): void
    {
        $u1 = $this->insertUser('susp1');
        $u2 = $this->insertUser('susp2');
        $u3 = $this->insertUser('susp3');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('standard', 'in_progress', :created_by, 3)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $p2 = $this->insertGamePlayer($gameId, $u2, 1);
        $p3 = $this->insertGamePlayer($gameId, $u3, 2);

        $suspicionId = $this->insertGameCard($gameId, 78, 'hand', $p1); // Suspicion
        $card9Id = $this->insertGameCard($gameId, 9, 'hand', $p2);
        $card3Id = $this->insertGameCard($gameId, 3, 'hand', $p2);
        $card106Id = $this->insertGameCard($gameId, 106, 'hand', $p3);
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $playResult = $this->games->playMood($gameId, $p1, $suspicionId, ['player_ids' => [$p2, $p3]]);
        self::assertTrue($playResult['pending_decision'] ?? false);

        // p3 hasn't answered yet -- responding out of turn is rejected.
        try {
            $this->games->respondToDecision($gameId, $p3, ['discarded_card_id_' . $p3 => $card106Id]);
            self::fail('Expected p3 to have no decision pending until p2 answers first');
        } catch (GameStateException) {
            // expected
        }

        $firstRespondResult = $this->games->respondToDecision($gameId, $p2, ['discarded_card_id_' . $p2 => $card9Id]);
        self::assertTrue($firstRespondResult['pending_decision'] ?? false);

        // The whole batch resolves together only once its last row is
        // answered -- p2's discard hasn't actually happened yet either.
        $registry = DefaultEffectRegistry::build();
        $state = (new BoardStateRepository($registry))->load($gameId);
        self::assertSame([$card9Id, $card3Id], $state->hand($p2));
        self::assertSame([$card106Id], $state->hand($p3));

        $finalRespondResult = $this->games->respondToDecision($gameId, $p3, ['discarded_card_id_' . $p3 => $card106Id]);
        self::assertArrayNotHasKey('pending_decision', $finalRespondResult);

        $state = (new BoardStateRepository($registry))->load($gameId);
        self::assertSame([$card3Id], $state->hand($p2));
        self::assertSame([], $state->hand($p3));
        self::assertCount(2, $state->discardPile());
    }

    /**
     * Disillusionment's queue asks every player at the table, including
     * the acting player themselves, starting with the next player in
     * turn order and wrapping around -- the round stays frozen through
     * all three responses.
     */
    public function testDisillusionmentQueuesEveryPlayerIncludingTheActorAndOnlyCompletesAfterTheLastResponds(): void
    {
        $u1 = $this->insertUser('disil1');
        $u2 = $this->insertUser('disil2');
        $u3 = $this->insertUser('disil3');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('standard', 'in_progress', :created_by, 3)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $p2 = $this->insertGamePlayer($gameId, $u2, 1);
        $p3 = $this->insertGamePlayer($gameId, $u3, 2);

        $disillusionmentId = $this->insertGameCard($gameId, 10, 'hand', $p1); // Disillusionment
        $disciplineId = $this->insertGameCard($gameId, 9, 'in_play', $p2); // Discipline, white
        $ambitionId = $this->insertGameCard($gameId, 53, 'in_play', $p2); // Ambition, black
        $anxietyId = $this->insertGameCard($gameId, 28, 'in_play', $p3); // Anxiety, blue
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $playResult = $this->games->playMood($gameId, $p1, $disillusionmentId, []);
        self::assertTrue($playResult['pending_decision'] ?? false);

        $result1 = $this->games->respondToDecision($gameId, $p2, ['chosen_color_' . $p2 => 'black']);
        self::assertTrue($result1['pending_decision'] ?? false);

        $result2 = $this->games->respondToDecision($gameId, $p3, ['chosen_color_' . $p3 => 'blue']);
        self::assertTrue($result2['pending_decision'] ?? false);

        $registry = DefaultEffectRegistry::build();
        $state = (new BoardStateRepository($registry))->load($gameId);
        self::assertTrue($state->isInPlay($disciplineId)); // nothing discarded until the queue's last player answers
        self::assertTrue($state->isInPlay($ambitionId));
        self::assertTrue($state->isInPlay($anxietyId));

        $result3 = $this->games->respondToDecision($gameId, $p1, ['chosen_color_' . $p1 => 'black']);
        self::assertArrayNotHasKey('pending_decision', $result3);

        $state = (new BoardStateRepository($registry))->load($gameId);
        self::assertTrue($state->isInPlay($disillusionmentId));
        self::assertTrue($state->isInPlay($disciplineId)); // white, not chosen by anyone
        self::assertFalse($state->isInPlay($ambitionId)); // black
        self::assertFalse($state->isInPlay($anxietyId)); // blue
    }

    /**
     * Malice's answer is a pair of mood ids, not a single value -- this
     * exercises the multi-select answer shape through the real HTTP-
     * service-layer respondToDecision() round trip.
     */
    public function testMalicePausesForTheTargetsOwnTwoMoodChoiceThenDiscardsMatchingColors(): void
    {
        $u1 = $this->insertUser('mal1');
        $u2 = $this->insertUser('mal2');
        $u3 = $this->insertUser('mal3');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('standard', 'in_progress', :created_by, 3)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $p2 = $this->insertGamePlayer($gameId, $u2, 1);
        $p3 = $this->insertGamePlayer($gameId, $u3, 2);

        $maliceId = $this->insertGameCard($gameId, 68, 'hand', $p1); // Malice
        $disciplineId = $this->insertGameCard($gameId, 9, 'in_play', $p2); // Discipline, white
        $charityId = $this->insertGameCard($gameId, 3, 'in_play', $p2); // Charity, white
        $dignityId = $this->insertGameCard($gameId, 8, 'in_play', $p3); // Dignity, white
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $playResult = $this->games->playMood($gameId, $p1, $maliceId, ['target_player_id' => $p2]);
        self::assertTrue($playResult['pending_decision'] ?? false);

        $registry = DefaultEffectRegistry::build();
        $state = (new BoardStateRepository($registry))->load($gameId);
        self::assertTrue($state->isInPlay($disciplineId)); // not discarded yet
        self::assertTrue($state->isInPlay($charityId));

        $respondResult = $this->games->respondToDecision($gameId, $p2, ['chosen_mood_ids' => [$disciplineId, $charityId]]);
        self::assertArrayNotHasKey('pending_decision', $respondResult);

        $state = (new BoardStateRepository($registry))->load($gameId);
        self::assertFalse($state->isInPlay($disciplineId));
        self::assertFalse($state->isInPlay($charityId));
        self::assertFalse($state->isInPlay($dignityId)); // shares white with the two chosen moods
    }

    /**
     * Malice's own color cascade (Dignity, shares white with the two
     * chosen moods) is never itself part of the target's submitted
     * `chosen_mood_ids` answer -- before BoardState::consumeCardMoves()
     * existed, this card's own discard was invisible in game history even
     * though it was a direct, non-random consequence of a real choice.
     */
    public function testRecentEventsDescribeEveryMoodMaliceDiscardsIncludingTheColorCascade(): void
    {
        $u1 = $this->insertUser('malevt1');
        $u2 = $this->insertUser('malevt2');
        $u3 = $this->insertUser('malevt3');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('standard', 'in_progress', :created_by, 3)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $p2 = $this->insertGamePlayer($gameId, $u2, 1);
        $p3 = $this->insertGamePlayer($gameId, $u3, 2);

        $maliceId = $this->insertGameCard($gameId, 68, 'hand', $p1); // Malice
        $disciplineId = $this->insertGameCard($gameId, 9, 'in_play', $p2); // Discipline, white -- chosen
        $charityId = $this->insertGameCard($gameId, 3, 'in_play', $p2); // Charity, white -- chosen
        $this->insertGameCard($gameId, 8, 'in_play', $p3); // Dignity, white -- cascade, never chosen
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $this->games->playMood($gameId, $p1, $maliceId, ['target_player_id' => $p2]);
        $this->games->respondToDecision($gameId, $p2, ['chosen_mood_ids' => [$disciplineId, $charityId]]);

        $events = $this->games->getState($gameId, $u1)['recent_events'];
        self::assertNotEmpty($events);
        // The moves are attributed to the 'pending_decision_resolved' event
        // (logged the moment resolveDecisions() actually made them), not
        // necessarily the most recent event overall -- so this checks
        // across every recent event's own description, not just events[0].
        $allDescriptions = implode(' | ', array_column($events, 'description'));
        self::assertStringContainsString('Discipline moved from play to the discard pile', $allDescriptions);
        self::assertStringContainsString('Charity moved from play to the discard pile', $allDescriptions);
        self::assertStringContainsString('Dignity moved from play to the discard pile', $allDescriptions);
    }

    /**
     * Cruelty's own discarded mood is a genuinely random pick
     * (array_rand()), never itself a submitted choice -- exactly the gap
     * BoardState::consumeCardMoves() closes: the pick doesn't need its own
     * bespoke reveal mechanism (unlike Paranoia/Curiosity's own hand
     * reveal) since the mood was already public while in play.
     */
    public function testRecentEventsDescribeCrueltysRandomlyDiscardedMood(): void
    {
        $u1 = $this->insertUser('cruelevt1');
        $u2 = $this->insertUser('cruelevt2');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('standard', 'in_progress', :created_by, 3)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $p2 = $this->insertGamePlayer($gameId, $u2, 1);

        $crueltyId = $this->insertGameCard($gameId, 61, 'hand', $p1); // Cruelty
        $this->insertGameCard($gameId, 9, 'in_play', $p2); // Discipline
        $this->insertGameCard($gameId, 3, 'in_play', $p2); // Charity
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $this->games->playMood($gameId, $p1, $crueltyId, ['opponent_player_ids' => [$p2]]);

        $events = $this->games->getState($gameId, $u1)['recent_events'];
        self::assertNotEmpty($events);
        $description = $events[0]['description'];
        self::assertMatchesRegularExpression(
            '/(Discipline|Charity) moved from play to the discard pile/',
            $description,
        );
    }

    /**
     * Harmony's own grant lets its owner play a second card straight from
     * the discard pile in the same turn -- exercising both zones a card
     * can actually be played from in one test.
     */
    public function testRecentEventsMentionWhichZoneACardWasPlayedFrom(): void
    {
        $u1 = $this->insertUser('playfromevt1');
        $this->insertUser('playfromevt2');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('standard', 'in_progress', :created_by, 3)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);

        $harmonyId = $this->insertGameCard($gameId, 123, 'hand', $p1); // Harmony
        $charityId = $this->insertGameCard($gameId, 3, 'discard', null); // Charity, already in the discard pile
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $this->games->playMood($gameId, $p1, $harmonyId, []);
        $this->games->playMood($gameId, $p1, $charityId, []);

        $events = $this->games->getState($gameId, $u1)['recent_events'];
        // Most recent first -- Charity (from discard) was played second.
        self::assertStringContainsString('played Charity from discard', $events[0]['description']);
        self::assertStringContainsString('played Harmony from hand', $events[1]['description']);
    }

    /**
     * Guile's ownership swap is permanent (no "give it back" tag at all --
     * see BoardState::giveInPlayToPlayer()'s own docblock), so this is the
     * plainest case that an ownership change gets logged at all, distinct
     * from the temporary Arrogance/Betrayal/Recklessness cases below.
     */
    public function testRecentEventsDescribeAMoodsOwnershipChange(): void
    {
        $u1 = $this->insertUser('ownevt1');
        $u2 = $this->insertUser('ownevt2');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('standard', 'in_progress', :created_by, 3)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $p2 = $this->insertGamePlayer($gameId, $u2, 1);

        $guileId = $this->insertGameCard($gameId, 40, 'hand', $p1); // Guile
        $card9Id = $this->insertGameCard($gameId, 9, 'hand', $p1); // discarded as Guile's cost
        $card8Id = $this->insertGameCard($gameId, 8, 'hand', $p1); // discarded as Guile's cost
        $charityId = $this->insertGameCard($gameId, 3, 'in_play', $p2); // Charity -- taken
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $this->games->playMood($gameId, $p1, $guileId, ['discard_card_ids' => [$card9Id, $card8Id], 'target_mood_id' => $charityId]);

        $events = $this->games->getState($gameId, $u1)['recent_events'];
        self::assertNotEmpty($events);
        self::assertStringContainsString('Charity changed ownership from ownevt2 to ownevt1', $events[0]['description']);
    }

    /**
     * Arrogance's own temporary-ownership tag has to survive the
     * RequiresOpponentDecision pause (the target's own choice of which
     * mood to give up) -- so this checks temporary_ownership only appears
     * once the target has actually answered, and disappears once Arrogance
     * itself leaves play (the mood reverting to its original owner).
     */
    public function testTemporaryOwnershipInfoForArroganceRevertsWhenArroganceLeavesPlay(): void
    {
        $u1 = $this->insertUser('tempown1');
        $u2 = $this->insertUser('tempown2');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('standard', 'in_progress', :created_by, 3)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $p2 = $this->insertGamePlayer($gameId, $u2, 1);

        $arroganceId = $this->insertGameCard($gameId, 82, 'hand', $p1); // Arrogance
        $charityId = $this->insertGameCard($gameId, 3, 'in_play', $p2); // Charity, white -- qualifies
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $playResult = $this->games->playMood($gameId, $p1, $arroganceId, ['opponent_player_id' => $p2]);
        self::assertTrue($playResult['pending_decision'] ?? false);

        $this->games->respondToDecision($gameId, $p2, ['chosen_mood_id' => $charityId]);

        $inPlay = $this->games->getState($gameId, $u1)['in_play'];
        $charity = self::findByCardId($inPlay, $charityId);
        self::assertSame(
            [
                'original_owner_game_player_id' => $p2,
                'original_owner_name' => 'tempown2',
                'source_card_id' => $arroganceId,
                'source_card_name' => 'Arrogance',
                'reverts' => 'when_source_leaves_play',
            ],
            $charity['temporary_ownership'],
        );

        // Arrogance itself is discarded once it leaves play (simulate by
        // directly mutating BoardState rather than needing a whole extra
        // card/turn to make that happen through real play).
        $registry = DefaultEffectRegistry::build();
        $repository = new BoardStateRepository($registry);
        $state = $repository->load($gameId);
        $state->moveInPlayToDiscard($arroganceId);
        $repository->save($gameId, $state);

        $inPlay = $this->games->getState($gameId, $u1)['in_play'];
        $charity = self::findByCardId($inPlay, $charityId);
        self::assertNull($charity['temporary_ownership']);
        self::assertSame($p2, $charity['owner_game_player_id']);
    }

    /**
     * Betrayal's own tag reverts at end-of-round, not "when the source
     * leaves play" -- distinct enough from Arrogance's own case above to
     * need its own test of the tag's 'reverts' value.
     */
    public function testTemporaryOwnershipInfoForBetrayalRevertsAfterScoring(): void
    {
        $u1 = $this->insertUser('tempown3');
        $u2 = $this->insertUser('tempown4');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('standard', 'in_progress', :created_by, 3)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $p2 = $this->insertGamePlayer($gameId, $u2, 1);

        $betrayalId = $this->insertGameCard($gameId, 56, 'hand', $p1); // Betrayal
        $charityId = $this->insertGameCard($gameId, 3, 'in_play', $p1); // Charity -- given away
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        // Which mood to give away is a pending decision the acting player
        // (not an opponent) answers immediately after Betrayal enters play
        // -- see BetrayalEffect's own docblock.
        $playResult = $this->games->playMood($gameId, $p1, $betrayalId, ['recipient_player_id' => $p2]);
        self::assertTrue($playResult['pending_decision'] ?? false);

        $this->games->respondToDecision($gameId, $p1, ['target_mood_id' => $charityId]);

        $inPlay = $this->games->getState($gameId, $u1)['in_play'];
        $charity = self::findByCardId($inPlay, $charityId);
        self::assertSame(
            [
                'original_owner_game_player_id' => $p1,
                'original_owner_name' => 'tempown3',
                'source_card_id' => $betrayalId,
                'source_card_name' => 'Betrayal',
                'reverts' => 'after_scoring',
            ],
            $charity['temporary_ownership'],
        );
    }

    /**
     * The core scenario BetrayalEffect's own RequiresOpponentDecision
     * redesign exists for: giving Betrayal itself away used to be
     * impossible through the ordinary GameService round trip (it wasn't
     * in play yet at choice-submission time, so it could never appear as
     * a candidate), even though nothing about the printed card text
     * excludes it. Exercises the full playMood() -> pending_decision:true
     * -> respondToDecision() round trip, not just BoardState directly.
     */
    public function testBetrayalCanGiveItselfAwayThroughTheFullRoundTrip(): void
    {
        $u1 = $this->insertUser('selfbetray1');
        $u2 = $this->insertUser('selfbetray2');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('standard', 'in_progress', :created_by, 3)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $p2 = $this->insertGamePlayer($gameId, $u2, 1);

        $betrayalId = $this->insertGameCard($gameId, 56, 'hand', $p1); // Betrayal -- p1's only mood
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $playResult = $this->games->playMood($gameId, $p1, $betrayalId, ['recipient_player_id' => $p2]);
        self::assertTrue($playResult['pending_decision'] ?? false);

        $pendingDecision = $this->games->getState($gameId, $u1)['round']['pending_decision'];
        self::assertSame('betrayal_give_mood', $pendingDecision['decision_type']);
        self::assertSame($p1, $pendingDecision['target_game_player_id']);
        self::assertTrue($pendingDecision['is_you']);

        $this->games->respondToDecision($gameId, $p1, ['target_mood_id' => $betrayalId]);

        $inPlay = $this->games->getState($gameId, $u1)['in_play'];
        $betrayal = self::findByCardId($inPlay, $betrayalId);
        self::assertSame($p2, $betrayal['owner_game_player_id']);
        self::assertSame(
            [
                'original_owner_game_player_id' => $p1,
                'original_owner_name' => 'selfbetray1',
                'source_card_id' => $betrayalId,
                'source_card_name' => 'Betrayal',
                'reverts' => 'after_scoring',
            ],
            $betrayal['temporary_ownership'],
        );

        $events = $this->games->getState($gameId, $u1)['recent_events'];
        $allDescriptions = implode(' | ', array_column($events, 'description'));
        self::assertStringContainsString('Betrayal changed ownership from selfbetray1 to selfbetray2', $allDescriptions);
    }

    /**
     * respondToDecision() used to log a closing 'mood_played' event once
     * the whole chain finished, on top of the 'pending_decision_created'
     * event the original play already logged and the
     * 'pending_decision_resolved' event the response itself just logged --
     * a redundant third "played Betrayal (recipient player: ...)" line
     * repeating exactly what the first event already said, with nothing
     * new to add (everything the response actually did was already fully
     * captured by 'pending_decision_resolved', logged right after
     * resolvePendingDecisions() itself ran). GameService::
     * respondToDecision() no longer logs that closing event at all.
     */
    public function testRespondToDecisionDoesNotLogARedundantClosingMoodPlayedEvent(): void
    {
        $u1 = $this->insertUser('noduplicate1');
        $u2 = $this->insertUser('noduplicate2');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('standard', 'in_progress', :created_by, 3)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $p2 = $this->insertGamePlayer($gameId, $u2, 1);

        $betrayalId = $this->insertGameCard($gameId, 56, 'hand', $p1); // Betrayal
        $charityId = $this->insertGameCard($gameId, 3, 'in_play', $p1); // Charity -- given away
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $this->games->playMood($gameId, $p1, $betrayalId, ['recipient_player_id' => $p2]);
        $this->games->respondToDecision($gameId, $p1, ['target_mood_id' => $charityId]);

        $events = $this->games->getState($gameId, $u1)['recent_events'];
        self::assertCount(2, $events);

        $descriptions = array_column($events, 'description');
        self::assertStringContainsString('waiting on a response', $descriptions[1]); // pending_decision_created, oldest
        self::assertStringStartsWith('A response to Betrayal was resolved', $descriptions[0]); // pending_decision_resolved, newest

        // The old, now-removed closing event's own exact phrasing (no
        // "waiting on a response" suffix, unlike the still-present first
        // event) must not appear anywhere.
        foreach ($descriptions as $description) {
            self::assertDoesNotMatchRegularExpression(
                '/^\S+ played Betrayal from hand \(recipient player: \S+\)$/',
                $description,
            );
        }
    }

    /**
     * total_score is a live "if the round ended right now" snapshot of the
     * board, not anything accumulated across previously-scored rounds --
     * so this checks it directly against a fixed set of already-in-play
     * moods, with no play/pass/scoring involved at all.
     */
    public function testPlayersExposeALiveBoardPointTotal(): void
    {
        $u1 = $this->insertUser('boardpts1');
        $u2 = $this->insertUser('boardpts2');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('standard', 'in_progress', :created_by, 3)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $p2 = $this->insertGamePlayer($gameId, $u2, 1);

        $this->insertGameCard($gameId, 55, 'in_play', $p1); // Apathy, value 4
        $this->insertGameCard($gameId, 3, 'in_play', $p1); // Charity, value 1
        $this->insertGameCard($gameId, 5, 'in_play', $p2); // Complacency, value 4
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $players = $this->games->getState($gameId, $u1)['players'];
        $p1Player = self::findByGamePlayerId($players, $p1);
        $p2Player = self::findByGamePlayerId($players, $p2);

        self::assertSame(5, $p1Player['total_score']);
        self::assertSame(4, $p2Player['total_score']);
    }

    /**
     * A mood leaving play (here, discarded as Bravado's own to-play cost)
     * must be reflected immediately -- proving this is a live snapshot,
     * not something that only updates once a round actually scores.
     */
    public function testTotalScoreDropsAsSoonAsAMoodLeavesPlay(): void
    {
        $u1 = $this->insertUser('boardpts3');
        $this->insertUser('boardpts4');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('standard', 'in_progress', :created_by, 3)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);

        $bravadoId = $this->insertGameCard($gameId, 84, 'hand', $p1); // Bravado
        $charityId = $this->insertGameCard($gameId, 3, 'in_play', $p1); // Charity, value 1 -- discarded as cost
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $before = self::findByGamePlayerId($this->games->getState($gameId, $u1)['players'], $p1);
        self::assertSame(1, $before['total_score']); // just Charity

        $this->games->playMood($gameId, $p1, $bravadoId, ['discard_mood_id' => $charityId]);

        $after = self::findByGamePlayerId($this->games->getState($gameId, $u1)['players'], $p1);
        self::assertSame(3, $after['total_score']); // Charity gone, Bravado (value 3) now in play
    }

    /**
     * A vanilla card with no afterScoring hook stays in play across a
     * round boundary (finishScoringAndAdvance() never clears the board on
     * its own -- only specific cards' own afterScoring tags remove
     * anything), so total_score must keep counting it once the next round
     * starts, not drop to 0 just because a round happened to score.
     */
    public function testTotalScoreSurvivesARoundScoringWhenNothingLeavesPlay(): void
    {
        [
            'gameId' => $gameId,
            'p1' => $p1,
            'p2' => $p2,
            'p3' => $p3,
            'u1' => $u1,
            'apathyId' => $apathyId,
        ] = $this->buildThreePlayerFixture();

        $this->games->playMood($gameId, $p1, $apathyId, []); // Apathy, value 4 -- no ability
        $this->games->pass($gameId, $p2);
        $result = $this->games->pass($gameId, $p3);
        self::assertTrue($result['round_scored']);

        $players = $this->games->getState($gameId, $u1)['players'];
        $p1Player = self::findByGamePlayerId($players, $p1);
        self::assertSame(4, $p1Player['total_score']); // Apathy is still sitting in play
    }

    /** @param array<int, array<string, mixed>> $players */
    private static function findByGamePlayerId(array $players, int $gamePlayerId): array
    {
        foreach ($players as $player) {
            if ($player['game_player_id'] === $gamePlayerId) {
                return $player;
            }
        }

        self::fail("No player with game_player_id {$gamePlayerId}");
    }

    /**
     * Exhilaration's doubling isn't tied to its own value -- it has to
     * survive a real load()/save() round trip and actually change the
     * recorded game_round_scores row, not just BoardState's in-memory
     * computation.
     */
    public function testExhilarationDoublesItsOwnersScoreThroughARealRoundTrip(): void
    {
        $u1 = $this->insertUser('exhil1');
        $u2 = $this->insertUser('exhil2');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('standard', 'in_progress', :created_by, 3)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $p2 = $this->insertGamePlayer($gameId, $u2, 1);

        $exhilarationId = $this->insertGameCard($gameId, 89, 'hand', $p1); // Exhilaration
        $apathyId = $this->insertGameCard($gameId, 55, 'in_play', $p1); // Apathy, value 4 -- sacrificed for the cost
        $this->insertGameCard($gameId, 106, 'in_play', $p1); // Zeal, value 3 -- survives, doubled
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $this->games->playMood($gameId, $p1, $exhilarationId, ['discard_mood_id' => $apathyId]);
        $result = $this->games->pass($gameId, $p2);

        self::assertTrue($result['round_scored']);

        $scoresStmt = $this->pdo->prepare(
            "SELECT s.game_player_id, s.score FROM game_round_scores s
             JOIN game_rounds r ON r.id = s.game_round_id
             WHERE r.game_id = :game_id AND r.round_number = 1"
        );
        $scoresStmt->execute(['game_id' => $gameId]);
        $scores = array_column($scoresStmt->fetchAll(), 'score', 'game_player_id');
        self::assertSame(6, (int) $scores[$p1]); // (Exhilaration 0 + Zeal 3) doubled
        self::assertSame(0, (int) $scores[$p2]);
    }

    public function testChaosReassignsOwnershipOfEveryMoodAfterReload(): void
    {
        $u1 = $this->insertUser('chaos1');
        $u2 = $this->insertUser('chaos2');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('standard', 'in_progress', :created_by, 3)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $p2 = $this->insertGamePlayer($gameId, $u2, 1);

        $chaosId = $this->insertGameCard($gameId, 85, 'hand', $p1); // Chaos
        $charityId = $this->insertGameCard($gameId, 3, 'in_play', $p2); // Charity
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $this->games->playMood($gameId, $p1, $chaosId, []);

        $registry = DefaultEffectRegistry::build();
        $state = (new BoardStateRepository($registry))->load($gameId);

        self::assertTrue($state->isInPlay($chaosId));
        self::assertTrue($state->isInPlay($charityId));
        foreach ([$state->ownerOf($chaosId), $state->ownerOf($charityId)] as $owner) {
            self::assertContains($owner, [$p1, $p2]);
        }
    }

    /**
     * Vulnerability's discardedThisRound flag lives on game_rounds, not
     * game_cards, so it needs its own persistence path
     * (updateRoundTurnState()) distinct from BoardStateRepository::save()
     * -- this proves a discard by a *different* player, in a separate
     * request, is reflected the moment it's reloaded (before the round
     * even ends), and that a fresh round resets it back to false.
     */
    public function testVulnerabilityPersistsAcrossATurnBoundaryThenResetsNextRound(): void
    {
        $u1 = $this->insertUser('vuln1');
        $u2 = $this->insertUser('vuln2');
        $u3 = $this->insertUser('vuln3');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('standard', 'in_progress', :created_by, 3)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $p2 = $this->insertGamePlayer($gameId, $u2, 1);
        $p3 = $this->insertGamePlayer($gameId, $u3, 2);

        $vulnerabilityId = $this->insertGameCard($gameId, 132, 'hand', $p1); // Vulnerability, base 1 / dice 7
        $dignityId = $this->insertGameCard($gameId, 8, 'hand', $p2); // Dignity
        $charityId = $this->insertGameCard($gameId, 3, 'hand', $p2); // Charity, value 1 -- discarded to pay Dignity's cost
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $this->games->playMood($gameId, $p1, $vulnerabilityId, []);

        $registry = DefaultEffectRegistry::build();
        $repository = new BoardStateRepository($registry);
        self::assertSame(1, $repository->load($gameId)->valueOf($vulnerabilityId)); // nothing discarded yet

        $this->games->playMood($gameId, $p2, $dignityId, ['discard_card_id' => $charityId]);

        // p2's discard, from a separate request, has to survive the reload
        // for p1's Vulnerability to reflect it -- the round isn't over yet
        // (p3 hasn't gone), so this can be observed directly.
        self::assertSame(7, $repository->load($gameId)->valueOf($vulnerabilityId));

        $result = $this->games->pass($gameId, $p3);
        self::assertTrue($result['round_scored']);

        $round2 = $this->fetchRound($gameId);
        self::assertSame(2, (int) $round2['round_number']);
        self::assertSame(0, (int) $round2['discarded_this_round']);
        self::assertSame(1, $repository->load($gameId)->valueOf($vulnerabilityId)); // reset for the new round
    }

    public function testEncouragementsBoostSurvivesReload(): void
    {
        $u1 = $this->insertUser('enc1');
        $u2 = $this->insertUser('enc2');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('standard', 'in_progress', :created_by, 3)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $p2 = $this->insertGamePlayer($gameId, $u2, 1);

        $encouragementId = $this->insertGameCard($gameId, 11, 'hand', $p1); // Encouragement
        $disciplineId = $this->insertGameCard($gameId, 9, 'in_play', $p1); // Discipline, base 6 / dice 3
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $this->games->playMood($gameId, $p1, $encouragementId, ['target_mood_id' => $disciplineId]);

        $registry = DefaultEffectRegistry::build();
        $state = (new BoardStateRepository($registry))->load($gameId);

        self::assertSame(6, $state->valueOf($disciplineId)); // higher of base(6)/dice(3)
    }

    /**
     * GameService::assertNoPendingDecision() is a plain SELECT before
     * writePendingBatch()'s own INSERT, so it can't by itself stop two
     * concurrent requests for the same round from both passing the check
     * before either one's batch exists -- the actual guarantee comes from
     * migration 0011's unique index, which allows any number of *resolved*
     * batches per round but at most one *open* one. This proves that
     * guarantee directly at the database level: two raw inserts for the
     * same round with neither resolved, bypassing GameService entirely,
     * the same way two concurrent requests would reach the database if a
     * check in between didn't happen to run first for both.
     */
    public function testDatabaseRejectsASecondSimultaneouslyOpenPendingBatchForTheSameRound(): void
    {
        $u1 = $this->insertUser('race1');
        $u2 = $this->insertUser('race2');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('standard', 'in_progress', :created_by, 3)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $this->insertGamePlayer($gameId, $u2, 1);
        $roundId = $this->insertGameRound($gameId, 1, $p1, $p1, 1);
        $cardId = $this->insertGameCard($gameId, 1, 'hand', $p1);

        $insertOpenBatch = function () use ($gameId, $roundId, $p1, $cardId): void {
            $stmt = $this->pdo->prepare(
                'INSERT INTO game_pending_decision_batches
                    (game_id, game_round_id, played_card_id, invocation_seq, initiating_game_player_id, top_level_choices, invocation_choices)
                 VALUES (:game_id, :round_id, :card_id, 0, :initiator, \'{}\', \'{}\')'
            );
            $stmt->execute(['game_id' => $gameId, 'round_id' => $roundId, 'card_id' => $cardId, 'initiator' => $p1]);
        };

        $insertOpenBatch(); // the "winner" of the race

        $this->expectException(PDOException::class);
        $this->expectExceptionCode('23000');
        $insertOpenBatch(); // the "loser" -- must be rejected, not silently succeed
    }

    /**
     * Enthusiasm's bonus ("you may score one of your moods an extra
     * time") is no longer applied automatically -- see RoundScorer's own
     * docblock for why. Both passes end round 1 (a 2-player round needs
     * no actual plays), which should pause for p1's own decision instead
     * of scoring immediately.
     */
    public function testEnthusiasmPausesForItsOwnersScoringDecisionThenAppliesTheChosenBonus(): void
    {
        $u1 = $this->insertUser('enth1');
        $u2 = $this->insertUser('enth2');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('standard', 'in_progress', :created_by, 3)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $p2 = $this->insertGamePlayer($gameId, $u2, 1);

        $enthusiasmId = $this->insertGameCard($gameId, 116, 'in_play', $p1); // Enthusiasm, value 0
        $this->insertGameCard($gameId, 8, 'in_play', $p1); // Dignity, base value 3
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $passResult1 = $this->games->pass($gameId, $p1);
        self::assertArrayNotHasKey('pending_decision', $passResult1); // just advances the turn to p2

        $passResult2 = $this->games->pass($gameId, $p2);
        self::assertTrue($passResult2['pending_decision'] ?? false, 'ending the round should pause for Enthusiasm\'s own decision');

        $state = $this->games->getState($gameId, $u1);
        self::assertTrue($state['round']['pending_decision']['is_you']);
        self::assertSame('enthusiasm_extra_score', $state['round']['pending_decision']['decision_type']);
        self::assertSame($enthusiasmId, $state['round']['pending_decision']['played_card_id']);
        self::assertSame([$p1 => 3, $p2 => 0], $state['round']['scoring_preview']['scores']); // undecided reads as declined

        $result = $this->games->respondToDecision($gameId, $p1, ['take_bonus' => true]);
        self::assertTrue($result['round_scored']);

        $scoreStmt = $this->pdo->prepare(
            'SELECT game_player_id, score FROM game_round_scores gs
             JOIN game_rounds gr ON gr.id = gs.game_round_id
             WHERE gr.game_id = :game_id AND gr.round_number = 1'
        );
        $scoreStmt->execute(['game_id' => $gameId]);
        $scores = [];
        foreach ($scoreStmt->fetchAll() as $row) {
            $scores[(int) $row['game_player_id']] = (int) $row['score'];
        }
        self::assertSame([$p1 => 6, $p2 => 0], $scores); // base 3 + Enthusiasm's own bonus (3)
    }

    public function testEnthusiasmAddsNoBonusWhenDeclined(): void
    {
        $u1 = $this->insertUser('enth3');
        $u2 = $this->insertUser('enth4');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('standard', 'in_progress', :created_by, 3)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $p2 = $this->insertGamePlayer($gameId, $u2, 1);

        $this->insertGameCard($gameId, 116, 'in_play', $p1);
        $this->insertGameCard($gameId, 8, 'in_play', $p1);
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $this->games->pass($gameId, $p1);
        $this->games->pass($gameId, $p2);
        $this->games->respondToDecision($gameId, $p1, ['take_bonus' => false]);

        $scoreStmt = $this->pdo->prepare(
            'SELECT score FROM game_round_scores gs JOIN game_rounds gr ON gr.id = gs.game_round_id
             WHERE gr.game_id = :game_id AND gr.round_number = 1 AND gs.game_player_id = :player_id'
        );
        $scoreStmt->execute(['game_id' => $gameId, 'player_id' => $p1]);
        self::assertSame(3, (int) $scoreStmt->fetchColumn()); // base only, no bonus
    }

    /**
     * Passion's bonus is a genuine choice of *which* opponent mood, not
     * just take-or-decline -- picking the lower-valued one is a valid,
     * deliberate answer (e.g. to avoid tipping off exactly how strong
     * a hand is, or simply because the player wants to), and the engine
     * shouldn't second-guess it.
     */
    public function testPassionScoresTheSpecificOpponentMoodChosenNotNecessarilyTheHighest(): void
    {
        $u1 = $this->insertUser('pass1');
        $u2 = $this->insertUser('pass2');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('standard', 'in_progress', :created_by, 3)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $p2 = $this->insertGamePlayer($gameId, $u2, 1);

        $this->insertGameCard($gameId, 97, 'in_play', $p1); // Passion, value 0
        $this->insertGameCard($gameId, 8, 'in_play', $p2); // Dignity, base value 3
        $this->insertGameCard($gameId, 3, 'in_play', $p2); // Charity, value 1
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $this->games->pass($gameId, $p1);
        $result = $this->games->pass($gameId, $p2);
        self::assertTrue($result['pending_decision'] ?? false);

        $finalResult = $this->games->respondToDecision($gameId, $p1, ['target_mood_id' => 3]); // Charity (1), not the higher Dignity (3)
        self::assertTrue($finalResult['round_scored']);

        $scoreStmt = $this->pdo->prepare(
            'SELECT game_player_id, score FROM game_round_scores gs JOIN game_rounds gr ON gr.id = gs.game_round_id
             WHERE gr.game_id = :game_id AND gr.round_number = 1'
        );
        $scoreStmt->execute(['game_id' => $gameId]);
        $scores = [];
        foreach ($scoreStmt->fetchAll() as $row) {
            $scores[(int) $row['game_player_id']] = (int) $row['score'];
        }
        self::assertSame([$p1 => 1, $p2 => 4], $scores); // p1: 0 + Charity's 1 (not Dignity's 3); p2 keeps both
    }

    public function testPassionRejectsAnInvalidTargetMood(): void
    {
        $u1 = $this->insertUser('pass3');
        $u2 = $this->insertUser('pass4');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('standard', 'in_progress', :created_by, 3)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $p2 = $this->insertGamePlayer($gameId, $u2, 1);

        $this->insertGameCard($gameId, 97, 'in_play', $p1); // Passion
        $this->insertGameCard($gameId, 8, 'in_play', $p1); // Dignity -- p1's OWN mood, not a valid Passion target
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $this->games->pass($gameId, $p1);
        $this->games->pass($gameId, $p2);

        $this->expectException(InvalidChoiceException::class);
        $this->games->respondToDecision($gameId, $p1, ['target_mood_id' => 8]); // p1's own mood, not an opponent's
    }

    /**
     * Two different players each have their own scoring decision this
     * round -- queued and answered one at a time, the round only
     * completing once both are in, the same one-at-a-time pattern
     * Disillusionment/Suspicion already use for their own multi-target
     * queues.
     */
    public function testMultipleScoringDecisionsAreQueuedOnePlayerAtATime(): void
    {
        $u1 = $this->insertUser('multi1');
        $u2 = $this->insertUser('multi2');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('standard', 'in_progress', :created_by, 3)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $p2 = $this->insertGamePlayer($gameId, $u2, 1);

        $this->insertGameCard($gameId, 116, 'in_play', $p1); // p1's Enthusiasm
        $this->insertGameCard($gameId, 8, 'in_play', $p1); // Dignity, base 3
        $this->insertGameCard($gameId, 97, 'in_play', $p2); // p2's Passion
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $this->games->pass($gameId, $p1);
        $result = $this->games->pass($gameId, $p2);
        self::assertTrue($result['pending_decision'] ?? false);

        $firstDecision = $this->games->getState($gameId, $u1)['round']['pending_decision'];
        self::assertSame($p1, $firstDecision['target_game_player_id']); // turn order starts with p1

        $afterFirst = $this->games->respondToDecision($gameId, $p1, ['take_bonus' => true]);
        self::assertTrue($afterFirst['pending_decision'] ?? false, 'p2 still has their own Passion decision outstanding');

        $secondDecision = $this->games->getState($gameId, $u2)['round']['pending_decision'];
        self::assertSame($p2, $secondDecision['target_game_player_id']);
        self::assertSame('passion_score_opponent_mood', $secondDecision['decision_type']);

        $afterSecond = $this->games->respondToDecision($gameId, $p2, []); // decline -- no valid opponent mood to take anyway
        self::assertTrue($afterSecond['round_scored']);
    }

    /**
     * The whole point of this feature: Sneakiness swaps its owner's final
     * score with a chosen opponent's *without touching the opponent's own
     * total*, so p1's own post-swap score (p2's original total) is
     * completely unaffected by whether p1 takes Passion's bonus -- what
     * changes is p2's post-swap score, which becomes p1's own pre-swap
     * total. Inflating that via Passion only hands p2 a bigger score once
     * the swap lands, which is never in p1's interest. Before this fix,
     * Passion always auto-took the highest opponent mood, which would
     * have been actively wrong here (needlessly boosting the very score
     * about to become the swap target's).
     */
    public function testDecliningPassionIsCorrectWhenSneakinessIsAboutToSwapTheScoreAway(): void
    {
        $u1 = $this->insertUser('sneak1');
        $u2 = $this->insertUser('sneak2');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('standard', 'in_progress', :created_by, 3)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $p2 = $this->insertGamePlayer($gameId, $u2, 1);

        $this->insertGameCard($gameId, 97, 'in_play', $p1); // p1's Passion, value 0
        $this->insertGameCard($gameId, 51, 'in_play', $p1); // p1's Sneakiness, base value 5 -- tags a swap with p2 below
        $this->insertGameCard($gameId, 8, 'in_play', $p2); // Dignity (3), a juicy Passion target
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        // Sneakiness's own target is chosen at play time -- tag it directly
        // via raw SQL the same way BoardState::setEffectState() would,
        // since this test only needs it already resolved, not re-played.
        $this->pdo->prepare(
            "UPDATE game_cards SET effect_state = '{\"swapScoreWithPlayerId\":" . $p2 . '}\' WHERE game_id = :game_id AND card_id = 51'
        )->execute(['game_id' => $gameId]);

        $this->games->pass($gameId, $p1);
        $result = $this->games->pass($gameId, $p2);
        self::assertTrue($result['pending_decision'] ?? false);

        $preview = $this->games->getState($gameId, $u1)['round']['scoring_preview'];
        self::assertSame([['game_player_id' => $p1, 'swaps_with_game_player_id' => $p2]], $preview['sneakiness_swaps']);
        self::assertSame([$p1 => 5, $p2 => 3], $preview['scores']); // undecided Passion reads as declined

        // Declining Passion: p1's pre-swap total stays at Sneakiness's own
        // 5 (Passion contributes 0), so after the swap p1 ends up with
        // p2's original 3 and p2 ends up with p1's original 5 -- not the
        // 8 they'd have gotten if p1 had also taken Dignity's 3.
        $finalResult = $this->games->respondToDecision($gameId, $p1, []); // decline
        self::assertTrue($finalResult['round_scored']);

        $scoreStmt = $this->pdo->prepare(
            'SELECT game_player_id, score FROM game_round_scores gs JOIN game_rounds gr ON gr.id = gs.game_round_id
             WHERE gr.game_id = :game_id AND gr.round_number = 1'
        );
        $scoreStmt->execute(['game_id' => $gameId]);
        $scores = [];
        foreach ($scoreStmt->fetchAll() as $row) {
            $scores[(int) $row['game_player_id']] = (int) $row['score'];
        }
        self::assertSame([$p1 => 3, $p2 => 5], $scores);
    }

    /**
     * playMood()/pass()/respondToDecision() are each wrapped in
     * withGameLock() (a MySQL named lock keyed by game id) to close a
     * broader race than migration 0011's pending-batch-specific one: two
     * genuinely concurrent requests for the same game (the same player's
     * two tabs) could otherwise both load a BoardState, both mutate it
     * independently, and have whichever save() runs last silently clobber
     * the other's changes. $this->pdo here is already a MySQL session
     * distinct from GameService's own Connection::get() singleton (each
     * opens its own PDO connection despite matching DB credentials), so
     * acquiring the lock directly on it -- and never releasing it within
     * this test -- genuinely simulates another in-flight request holding
     * the lock, without needing real OS-level concurrency (the blocking
     * happens server-side, between MySQL sessions). A short injected
     * timeout keeps this test fast rather than waiting out the real
     * (generous, production-appropriate) default.
     */
    public function testPlayMoodFailsWithBusyErrorWhenAnotherRequestHoldsTheGameLock(): void
    {
        $u1 = $this->insertUser('lock1');
        $u2 = $this->insertUser('lock2');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('standard', 'in_progress', :created_by, 3)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $this->insertGamePlayer($gameId, $u2, 1);
        $this->insertGameCard($gameId, 8, 'hand', $p1);
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $lockStmt = $this->pdo->prepare('SELECT GET_LOCK(?, 5)');
        $lockStmt->execute(["moodswings_game:{$gameId}"]);
        self::assertSame(1, (int) $lockStmt->fetchColumn(), 'test setup: failed to acquire the simulated lock');

        $registry = DefaultEffectRegistry::build();
        $lockedGames = new GameService(
            new BoardStateRepository($registry),
            new MoodPlayService($registry),
            new RoundScorer(),
            1, // seconds -- short so this test doesn't wait out the real 10s default
        );

        try {
            $this->expectException(GameStateException::class);
            $this->expectExceptionMessage('busy');
            $lockedGames->playMood($gameId, $p1, 8, []);
        } finally {
            $this->pdo->prepare('SELECT RELEASE_LOCK(?)')->execute(["moodswings_game:{$gameId}"]);
        }
    }
}
