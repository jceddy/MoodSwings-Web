<?php

declare(strict_types=1);

namespace MoodSwings\Tests\Game;

use MoodSwings\Deck\NotAuthorizedToAccessDecklistException;
use MoodSwings\Deck\UserDecklistService;
use MoodSwings\Friends\FriendshipService;
use MoodSwings\Game\BoardStateRepository;
use MoodSwings\Game\Exceptions\GameStateException;
use MoodSwings\Game\GameService;
use MoodSwings\Repository\FriendshipRepository;
use MoodSwings\Repository\UserDecklistRepository;
use MoodSwings\Repository\UserRepository;
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
        $pdo->exec('TRUNCATE TABLE draft_round_picks');
        $pdo->exec('TRUNCATE TABLE draft_winston_state');
        $pdo->exec('TRUNCATE TABLE draft_grid_state');
        $pdo->exec('TRUNCATE TABLE draft_match_players');
        $pdo->exec('TRUNCATE TABLE draft_matches');
        $pdo->exec('TRUNCATE TABLE game_initial_card_passes');
        $pdo->exec('TRUNCATE TABLE game_team_decisions');
        $pdo->exec('TRUNCATE TABLE game_pending_decisions');
        $pdo->exec('TRUNCATE TABLE game_pending_decision_batches');
        $pdo->exec('TRUNCATE TABLE game_round_scores');
        $pdo->exec('TRUNCATE TABLE game_cards');
        $pdo->exec('TRUNCATE TABLE game_rounds');
        $pdo->exec('TRUNCATE TABLE game_players');
        $pdo->exec('TRUNCATE TABLE games');
        $pdo->exec('TRUNCATE TABLE user_decklists');
        $pdo->exec('TRUNCATE TABLE user_lifetime_stats');
        $pdo->exec('TRUNCATE TABLE friendships');
        $pdo->exec('TRUNCATE TABLE users');
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

        putenv("DB_HOST={$host}");
        putenv("DB_PORT={$port}");
        putenv("DB_NAME={$name}");
        putenv("DB_USER={$user}");
        putenv("DB_PASSWORD={$password}");

        $this->pdo = $pdo;

        $registry = DefaultEffectRegistry::build();
        $userDecklists = new UserDecklistService(
            new UserDecklistRepository(),
            new FriendshipService(new UserRepository(), new FriendshipRepository()),
        );
        $this->games = new GameService(
            new BoardStateRepository($registry),
            new MoodPlayService($registry),
            new RoundScorer(),
            $userDecklists,
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

    private function fetchUsername(int $userId): string
    {
        $stmt = $this->pdo->prepare('SELECT username FROM users WHERE id = :id');
        $stmt->execute(['id' => $userId]);

        return (string) $stmt->fetchColumn();
    }

    /** @param int[] $cardIds */
    private function insertSavedDecklist(int $userId, string $name, array $cardIds, string $visibility = 'private'): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO user_decklists (user_id, name, card_ids, visibility) VALUES (:user_id, :name, :card_ids, :visibility)'
        );
        $stmt->execute([
            'user_id' => $userId,
            'name' => $name,
            'card_ids' => json_encode($cardIds),
            'visibility' => $visibility,
        ]);

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

    public function testCreateGameWithSavedDecklistIdUsesItsCardIds(): void
    {
        $creator = $this->insertUser('saved-deck-alice');
        $bob = $this->insertUser('saved-deck-bob');
        $decklistId = $this->insertSavedDecklist($creator, 'My Saved Deck', range(1, 15));

        $gameId = $this->games->createGame($creator, [$creator, $bob], deckType: 'custom', savedDecklistId: $decklistId);

        $game = $this->fetchGame($gameId);
        self::assertSame('custom', $game['deck_type']);
        self::assertSame('My Saved Deck', $game['custom_deck_name']);
        self::assertSame(range(1, 15), array_map(intval(...), json_decode($game['custom_deck_card_ids'], true)));
    }

    public function testCreateGameWithSavedDecklistEnforcesMinimumCardCount(): void
    {
        $creator = $this->insertUser('saved-deck-few-alice');
        $bob = $this->insertUser('saved-deck-few-bob');
        $carol = $this->insertUser('saved-deck-few-carol');
        // 3 players need at least 30 cards -- this saved deck only has 15.
        $decklistId = $this->insertSavedDecklist($creator, 'Too Small', range(1, 15));

        $this->expectException(GameStateException::class);
        $this->expectExceptionMessage('at least 30 are required for 3 players');

        $this->games->createGame($creator, [$creator, $bob, $carol], deckType: 'custom', savedDecklistId: $decklistId);
    }

    public function testCreateGameRejectsSavedDecklistNotOwnedOrShared(): void
    {
        $owner = $this->insertUser('saved-deck-owner');
        $creator = $this->insertUser('saved-deck-stranger');
        $bob = $this->insertUser('saved-deck-stranger-bob');
        $decklistId = $this->insertSavedDecklist($owner, 'Not Yours', range(1, 15));

        $this->expectException(NotAuthorizedToAccessDecklistException::class);

        $this->games->createGame($creator, [$creator, $bob], deckType: 'custom', savedDecklistId: $decklistId);
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

    public function testSubmitCustomDuelDeckWithSavedDecklistId(): void
    {
        ['gameId' => $gameId, 'p1' => $p1, 'u1' => $u1] = $this->buildCustomDuelFixture(minCards: 7);
        $decklistId = $this->insertSavedDecklist($u1, 'My Duel Deck', [3, 4, 5, 6, 7, 8, 9]);

        $this->games->submitCustomDuelDeck($gameId, $p1, null, savedDecklistId: $decklistId);

        $p1Row = $this->pdo->prepare('SELECT custom_deck_name, custom_deck_card_ids FROM game_players WHERE id = :id');
        $p1Row->execute(['id' => $p1]);
        $row = $p1Row->fetch();
        self::assertSame('My Duel Deck', $row['custom_deck_name']);
        self::assertSame([3, 4, 5, 6, 7, 8, 9], array_map(intval(...), json_decode($row['custom_deck_card_ids'], true)));
    }

    public function testSubmitCustomDuelDeckWithSavedDecklistStillValidatesAgainstDuelRules(): void
    {
        ['gameId' => $gameId, 'p1' => $p1, 'u1' => $u1] = $this->buildCustomDuelFixture(minCards: 10);
        // Only 2 cards -- below this game's own 10-card minimum.
        $decklistId = $this->insertSavedDecklist($u1, 'Too Small', [3, 4]);

        $this->expectException(GameStateException::class);
        $this->expectExceptionMessage('has only 2 card(s), but at least 10 are required');

        $this->games->submitCustomDuelDeck($gameId, $p1, null, savedDecklistId: $decklistId);
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

        self::assertNull($summary['draft_match_id'], 'only Quick Draft games belong to a match');
        self::assertNull($summary['match_game_number']);
        self::assertNull($summary['draft_match']);
    }

    /**
     * is_awaiting_your_response is a distinct flag from is_your_turn --
     * Compulsion's target has a decision on them regardless of whose turn
     * it nominally is (the whole round is frozen while it's outstanding,
     * see the "round is frozen" assertion in
     * testCompulsionPausesForP2sOwnChoiceAndOnlyCompletesAfterTheyRespond()).
     */
    public function testListGamesForUserFlagsAwaitingYourResponseForAPendingDecisionTargetingYou(): void
    {
        $u1 = $this->insertUser('lobby-comp1');
        $u2 = $this->insertUser('lobby-comp2');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('standard', 'in_progress', :created_by, 3)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $p2 = $this->insertGamePlayer($gameId, $u2, 1);

        $compulsionId = $this->insertGameCard($gameId, 86, 'hand', $p1); // Compulsion
        $card3Id = $this->insertGameCard($gameId, 3, 'hand', $p2);
        $this->insertGameCard($gameId, 7, 'hand', $p2);
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $u1GamesBefore = $this->games->listGamesForUser($u1);
        $u2GamesBefore = $this->games->listGamesForUser($u2);
        self::assertFalse($u1GamesBefore[0]['is_awaiting_your_response']);
        self::assertFalse($u2GamesBefore[0]['is_awaiting_your_response']);

        $this->games->playMood($gameId, $p1, $compulsionId, ['target_player_id' => $p2]);

        // p1 played the card (it's still their own turn otherwise), but
        // the decision is on p2 -- is_awaiting_your_response should track
        // p2, not whoever's turn it is.
        $u1Games = $this->games->listGamesForUser($u1);
        $u2Games = $this->games->listGamesForUser($u2);
        self::assertFalse($u1Games[0]['is_awaiting_your_response']);
        self::assertTrue($u2Games[0]['is_awaiting_your_response']);

        $this->games->respondToDecision($gameId, $p2, ['given_card_id' => $card3Id]);

        $u2GamesAfter = $this->games->listGamesForUser($u2);
        self::assertFalse($u2GamesAfter[0]['is_awaiting_your_response'], 'no longer awaiting a response once answered');
    }

    /**
     * current_turn_username/awaiting_response_usernames are the
     * all-players generalization of is_your_turn/is_awaiting_your_response
     * -- the lobby uses them to put a play-arrow icon next to whichever
     * player's own name current_turn_game_player_id belongs to, and a
     * waiting-hourglass icon next to every name isAwaitingResponseFrom()
     * returns true for. p1 played Compulsion, so current_turn_username
     * stays p1 (turn hasn't moved off them) even though the round is
     * actually frozen on p2's own answer -- p2's name is the one that
     * shows up in awaiting_response_usernames, not p1's.
     */
    public function testListGamesForUserExposesCurrentTurnAndAwaitingResponseUsernamesForEveryPlayer(): void
    {
        $u1 = $this->insertUser('lobby-wait1');
        $u2 = $this->insertUser('lobby-wait2');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('standard', 'in_progress', :created_by, 3)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $p2 = $this->insertGamePlayer($gameId, $u2, 1);

        $compulsionId = $this->insertGameCard($gameId, 86, 'hand', $p1); // Compulsion
        $card3Id = $this->insertGameCard($gameId, 3, 'hand', $p2);
        $this->insertGameCard($gameId, 7, 'hand', $p2);
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $u1GamesBefore = $this->games->listGamesForUser($u1);
        self::assertSame('lobby-wait1', $u1GamesBefore[0]['current_turn_username']);
        self::assertSame([], $u1GamesBefore[0]['awaiting_response_usernames'], 'nothing pending yet');

        $this->games->playMood($gameId, $p1, $compulsionId, ['target_player_id' => $p2]);

        $u1Games = $this->games->listGamesForUser($u1);
        $u2Games = $this->games->listGamesForUser($u2);
        self::assertTrue($u1Games[0]['is_your_turn'], 'the turn has not moved off p1 yet');
        self::assertSame('lobby-wait1', $u1Games[0]['current_turn_username']);
        self::assertSame(['lobby-wait2'], $u1Games[0]['awaiting_response_usernames']);
        self::assertSame($u1Games[0]['awaiting_response_usernames'], $u2Games[0]['awaiting_response_usernames'], 'identical regardless of viewer');
        self::assertTrue($u2Games[0]['is_awaiting_your_response'], 'p2 is the one Compulsion actually targets');

        $this->games->respondToDecision($gameId, $p2, ['given_card_id' => $card3Id]);

        $u1GamesAfter = $this->games->listGamesForUser($u1);
        self::assertSame([], $u1GamesAfter[0]['awaiting_response_usernames'], 'no longer waiting on anyone once answered');
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

    /**
     * The lobby's own "who won" line (issue #136) depends on
     * winner_usernames staying empty until there's actually a winner to
     * name -- neither a still-waiting nor an in-progress game has one yet.
     */
    public function testListGamesForUserWinnerUsernamesEmptyUntilCompleted(): void
    {
        $creator = $this->insertUser('winnames-alice');
        $bob = $this->insertUser('winnames-bob');

        $gameId = $this->games->createGame($creator, [$creator, $bob]);
        self::assertSame([], $this->games->listGamesForUser($creator)[0]['winner_usernames']);

        $this->games->startGame($gameId);
        self::assertSame([], $this->games->listGamesForUser($creator)[0]['winner_usernames']);

        $winnerId = $this->games->gamePlayerIdFor($gameId, $creator);
        $this->pdo->prepare(
            "UPDATE games SET status = 'completed', completed_at = NOW(), winner_game_player_id = :winner WHERE id = :id"
        )->execute(['winner' => $winnerId, 'id' => $gameId]);

        self::assertSame(['winnames-alice'], $this->games->listGamesForUser($creator)[0]['winner_usernames']);
    }

    /**
     * Mirrors getState()'s own "credit the whole winning team" logic (see
     * "Open Team Play" in php-app/README.md) -- a team-format win's
     * winner_usernames must name both teammates, not just the
     * representative winner_game_player_id the games row itself stores.
     */
    public function testListGamesForUserWinnerUsernamesIncludesBothTeammatesForATeamWin(): void
    {
        $u1 = $this->insertUser('winteam-alice');
        $u2 = $this->insertUser('winteam-bob');
        $u3 = $this->insertUser('winteam-carol');
        $u4 = $this->insertUser('winteam-dave');

        // u1 (creator) + u2 (chosen partner) become team 0; u3/u4 are team 1.
        $gameId = $this->games->createGame($u1, [$u1, $u2, $u3, $u4], 'team', 3, 'structure', null, null, $u2);

        $winnerRepresentative = $this->games->gamePlayerIdFor($gameId, $u1);
        $this->pdo->prepare(
            "UPDATE games SET status = 'completed', completed_at = NOW(), winner_game_player_id = :winner, winner_team_id = 0 WHERE id = :id"
        )->execute(['winner' => $winnerRepresentative, 'id' => $gameId]);

        $summary = $this->games->listGamesForUser($u1)[0];
        self::assertEqualsCanonicalizing(['winteam-alice', 'winteam-bob'], $summary['winner_usernames']);
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

    /**
     * value_locked distinguishes a permanent one-time "after playing this
     * mood, ... this mood's value becomes N" trigger (Dignity here) from a
     * continuously recomputed "while in play" value -- see
     * BoardState::setValueOverride() and "Card art rendering" in
     * web-static/README.md, which uses this to rotate the card art 180deg.
     */
    public function testGetStateMarksValueLockedTrueOnceDignitysAfterPlayingTriggerFires(): void
    {
        $u1 = $this->insertUser('valuelocked1');
        $u2 = $this->insertUser('valuelocked2');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('standard', 'in_progress', :created_by, 3)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $this->insertGamePlayer($gameId, $u2, 1);

        $dignityId = $this->insertGameCard($gameId, 8, 'hand', $p1); // Dignity: base 3, dice/alt 5
        $charityId = $this->insertGameCard($gameId, 3, 'hand', $p1); // Charity, base value 1 -- qualifies
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $this->games->playMood($gameId, $p1, $dignityId, ['discard_card_id' => $charityId]);

        $inPlay = $this->games->getState($gameId, $u1)['in_play'];
        self::assertSame(5, $inPlay[0]['value']);
        self::assertTrue($inPlay[0]['value_locked']);
    }

    public function testGetStateLeavesValueLockedFalseWhenDignitysTriggerIsDeclined(): void
    {
        $u1 = $this->insertUser('valuenotlocked1');
        $u2 = $this->insertUser('valuenotlocked2');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('standard', 'in_progress', :created_by, 3)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $this->insertGamePlayer($gameId, $u2, 1);

        $dignityId = $this->insertGameCard($gameId, 8, 'hand', $p1); // Dignity: base 3, dice/alt 5
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $this->games->playMood($gameId, $p1, $dignityId, []); // no discard offered

        $inPlay = $this->games->getState($gameId, $u1)['in_play'];
        self::assertSame(3, $inPlay[0]['value']);
        self::assertFalse($inPlay[0]['value_locked']);
    }

    /**
     * Determination's alt value is a "while in play" condition, recomputed
     * live every time (see valueOf()) rather than ever stored via
     * setValueOverride() -- value_locked must stay false even while its
     * live value happens to equal alt_value, unlike Dignity's permanent
     * trigger above.
     */
    public function testGetStateLeavesValueLockedFalseForDeterminationsWhileInPlayValue(): void
    {
        $u1 = $this->insertUser('determination1');
        $u2 = $this->insertUser('determination2');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('standard', 'in_progress', :created_by, 3)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $this->insertGamePlayer($gameId, $u2, 1);

        $determinationId = $this->insertGameCard($gameId, 112, 'hand', $p1); // Determination: base 3, dice/alt 6, green
        $this->insertGameCard($gameId, 118, 'in_play', $p1); // Fascination, green
        $this->insertGameCard($gameId, 133, 'in_play', $p1); // Wonder, green -- 3rd green mood triggers the threshold
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $this->games->playMood($gameId, $p1, $determinationId, []);

        $inPlay = $this->games->getState($gameId, $u1)['in_play'];
        $determination = array_values(array_filter($inPlay, fn (array $card): bool => $card['card_id'] === $determinationId))[0];
        self::assertSame(6, $determination['value']); // dynamically at alt_value...
        self::assertFalse($determination['value_locked']); // ...but never locked in
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

    /**
     * Hurt Feelings' own second base play must be described as
     * attributable to Hurt Feelings, not render as an indistinguishable
     * second "Your normal turn" -- see
     * GameService::describePlayGrant()/sourceCardNameFor(). Using it
     * (the second play of the turn) must also attribute the resulting
     * event to Hurt Feelings, the same way any other granted extra play
     * already does -- unlike consuming the ordinary base allowance,
     * which stays silent (see BoardState::$pendingGrantUsed's docblock).
     */
    public function testHurtFeelingsExtraPlayIsDescribedAsSuchInPlayGrantsAndTheEventLog(): void
    {
        $u1 = $this->insertUser('hurtfeelings1');
        $u2 = $this->insertUser('hurtfeelings2');
        $u3 = $this->insertUser('hurtfeelings3');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('standard', 'in_progress', :created_by, 3)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $this->insertGamePlayer($gameId, $u2, 1);
        $p3 = $this->insertGamePlayer($gameId, $u3, 2);

        $apathyId = $this->insertGameCard($gameId, 55, 'hand', $p3); // Apathy -- blank, no after-playing effect
        $complacencyId = $this->insertGameCard($gameId, 5, 'hand', $p3); // Complacency -- same
        $roundId = $this->insertGameRound($gameId, 1, $p1, $p3, 2, hurtFeelingsPlayerId: $p3);

        $this->pdo->prepare('UPDATE game_rounds SET pending_play_grants = :grants WHERE id = :id')
            ->execute(['grants' => json_encode([null, ['sourceLabel' => 'Hurt Feelings']]), 'id' => $roundId]);

        $playGrants = $this->games->getState($gameId, $u1)['round']['play_grants'];

        self::assertCount(2, $playGrants);
        self::assertSame('Your normal turn', $playGrants[0]['description']);
        self::assertNull($playGrants[0]['source_card_id']);
        self::assertSame('An extra play from Hurt Feelings', $playGrants[1]['description']);
        self::assertNull($playGrants[1]['source_card_id']);

        $this->games->playMood($gameId, $p3, $apathyId, []); // consumes the ordinary base allowance
        // Consumes Hurt Feelings' own extra play -- also the last
        // outstanding play this turn, so it scores (and completes) the
        // round immediately afterward, same as any other last play would.
        $this->games->playMood($gameId, $p3, $complacencyId, []);

        $events = $this->games->getState($gameId, $u1)['recent_events'];
        $mentioningHurtFeelingsUsage = array_values(array_filter(
            $events,
            fn (array $event) => str_contains($event['description'], 'using an extra play from Hurt Feelings'),
        ));
        self::assertCount(1, $mentioningHurtFeelingsUsage);
        self::assertStringContainsString($complacencyName = $this->cardNameFor(5), $mentioningHurtFeelingsUsage[0]['description']);
    }

    private function cardNameFor(int $catalogCardId): string
    {
        $stmt = $this->pdo->prepare('SELECT name FROM cards WHERE id = :id');
        $stmt->execute(['id' => $catalogCardId]);

        return (string) $stmt->fetchColumn();
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

    /**
     * Regression test: BoardState::clearEndOfRoundSuppressions() existed
     * and was unit-tested (see MoodPlayServiceTest), but nothing in
     * GameService's actual round-advance paths ever called it -- so
     * Repentance's (and Scorn's) 'end_of_round' suppression persisted past
     * the round it was cast in instead of lifting, since suppression state
     * round-trips through game_cards across a real load()/save() boundary.
     */
    public function testRepentanceSuppressionLiftsAfterTheRoundItWasCastInIsScored(): void
    {
        $u1 = $this->insertUser('repentanceclear1');
        $u2 = $this->insertUser('repentanceclear2');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('standard', 'in_progress', :created_by, 3)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $p2 = $this->insertGamePlayer($gameId, $u2, 1);

        $repentanceId = $this->insertGameCard($gameId, 23, 'hand', $p1); // Repentance
        $courageId = $this->insertGameCard($gameId, 7, 'in_play', $p2); // Courage, value 1
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $this->games->playMood($gameId, $p1, $repentanceId, ['value' => 1]);

        $inPlayDuringRound1 = $this->games->getState($gameId, $u1)['in_play'];
        $courageDuringRound1 = self::findByCardId($inPlayDuringRound1, $courageId);
        self::assertTrue($courageDuringRound1['is_suppressed']);
        self::assertSame('end_of_round', $courageDuringRound1['suppression_expiry']);

        $this->games->pass($gameId, $p2); // p1 already used their only play -- this ends round 1

        $round2 = $this->fetchRound($gameId);
        self::assertSame(2, (int) $round2['round_number']);

        $inPlayDuringRound2 = $this->games->getState($gameId, $u1)['in_play'];
        $courageDuringRound2 = self::findByCardId($inPlayDuringRound2, $courageId);
        self::assertFalse($courageDuringRound2['is_suppressed']);
        self::assertNull($courageDuringRound2['suppression_expiry']);
    }

    /**
     * Regression test: with a green Joy already in play and an opponent's
     * Ambivalence (blue, base 6 / alt 3 once 2+ red/green moods are in
     * play) also in play, playing a red Shock should be able to target
     * that Ambivalence -- Shock's own color is what tips the red/green
     * count to 2, but only once Shock is actually in play, which happens
     * moments before its own "value of 3 or less" targeting resolves (see
     * BoardState::valueOfAsIfAlsoInPlay()). Before the fix, Ambivalence
     * never appeared in the served candidate_card_ids at all, since it was
     * computed from Ambivalence's stale pre-play value (6, ineligible).
     */
    public function testShockCanTargetAnAmbivalenceThatOnlyQualifiesOnceShockItselfIsInPlay(): void
    {
        $u1 = $this->insertUser('shockambiv1');
        $u2 = $this->insertUser('shockambiv2');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('standard', 'in_progress', :created_by, 3)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $p2 = $this->insertGamePlayer($gameId, $u2, 1);

        $joyId = $this->insertGameCard($gameId, 125, 'in_play', $p1); // Joy, green
        $ambivalenceId = $this->insertGameCard($gameId, 27, 'in_play', $p2); // Ambivalence, blue, base 6 / alt 3
        $shockId = $this->insertGameCard($gameId, 101, 'hand', $p1); // Shock, red
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $stateBeforePlaying = $this->games->getState($gameId, $u1);
        self::assertSame(6, self::findByCardId($stateBeforePlaying['in_play'], $ambivalenceId)['value']);

        $shockCard = self::findByCardId($stateBeforePlaying['you']['hand'], $shockId);
        $targetField = self::findFieldByKey($shockCard['choice_fields'], 'target_mood_ids');
        self::assertContains($ambivalenceId, $targetField['candidate_card_ids']);

        $this->games->playMood($gameId, $p1, $shockId, ['target_mood_ids' => [$ambivalenceId]]);

        $stateAfterPlaying = $this->games->getState($gameId, $u1);
        self::assertNotContains(
            $ambivalenceId,
            array_column($stateAfterPlaying['in_play'], 'card_id'),
        );
        self::assertContains(
            $ambivalenceId,
            array_column($stateAfterPlaying['discard_pile'], 'card_id'),
        );
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

    // -- Full event log (issue #98) ------------------------------------------

    /**
     * fullEventLog() is chronological oldest-first (the opposite order
     * from recentEvents()'s own newest-first slice), and -- unlike
     * recentEvents()'s hardcoded 15-row limit -- returns every event with
     * no cap at all, so a game with more than 15 events still gets every
     * one of them back.
     */
    public function testFullEventLogReturnsEveryEventChronologicallyUnlikeRecentEventsFifteenRowLimit(): void
    {
        $u1 = $this->insertUser('fulllogorder1');
        $u2 = $this->insertUser('fulllogorder2');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('standard', 'in_progress', :created_by, 99)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $p2 = $this->insertGamePlayer($gameId, $u2, 1);
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        // 18 alternating pass()es -- comfortably more than recentEvents()'s
        // own 15-row limit. Each pass() logs its own 'turn_passed' event,
        // but with only 2 players every 2nd pass also completes and
        // scores the round (wins_needed: 99 above only keeps the GAME
        // itself from ever actually completing, not the individual
        // rounds within it) -- so this isn't purely 18 'turn_passed' rows,
        // it's however many game_events rows actually accumulate, fetched
        // straight from the database below rather than assumed.
        $currentPlayerId = $p1;
        for ($i = 0; $i < 18; $i++) {
            $this->games->pass($gameId, $currentPlayerId);
            $currentPlayerId = $currentPlayerId === $p1 ? $p2 : $p1;
        }

        $recentEvents = $this->games->getState($gameId, $u1)['recent_events'];
        self::assertCount(15, $recentEvents, "recentEvents()'s own hardcoded limit");

        $countStmt = $this->pdo->prepare('SELECT COUNT(*) FROM game_events WHERE game_id = :game_id');
        $countStmt->execute(['game_id' => $gameId]);
        $actualEventCount = (int) $countStmt->fetchColumn();
        self::assertGreaterThan(15, $actualEventCount, 'sanity check that this scenario really does exceed the 15-row limit');

        $fullLog = $this->games->fullEventLog($gameId);
        self::assertCount($actualEventCount, $fullLog, 'fullEventLog() has no cap at all');

        $ids = array_column($fullLog, 'id');
        $sortedIds = $ids;
        sort($sortedIds);
        self::assertSame($sortedIds, $ids, 'oldest first -- ascending id order');
    }

    /**
     * Each entry carries the raw fields the frontend's "download data"
     * button (see "Game log" in web-static/README.md) turns into a JSON
     * export, alongside the same describeEvent()-rendered description
     * recentEvents() itself uses -- both views can never drift out of
     * phrasing sync since they share that one rendering method.
     */
    public function testFullEventLogEntriesCarryRawFieldsAlongsideTheRenderedDescription(): void
    {
        $u1 = $this->insertUser('fulllograw1');
        $u2 = $this->insertUser('fulllograw2');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('standard', 'in_progress', :created_by, 3)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $this->insertGamePlayer($gameId, $u2, 1);

        $encouragementId = $this->insertGameCard($gameId, 11, 'hand', $p1); // Encouragement
        $dignityId = $this->insertGameCard($gameId, 8, 'in_play', $p1); // Dignity -- the mood targeted
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $this->games->playMood($gameId, $p1, $encouragementId, ['target_mood_id' => $dignityId]);

        $fullLog = $this->games->fullEventLog($gameId);
        self::assertCount(1, $fullLog);
        $entry = $fullLog[0];

        self::assertSame('mood_played', $entry['event_type']);
        self::assertSame(1, $entry['round_number']);
        self::assertSame($p1, $entry['acting_game_player_id']);
        self::assertSame('fulllograw1', $entry['acting_username']);
        self::assertSame($encouragementId, $entry['card_id']);
        self::assertSame('Encouragement', $entry['card_name']);
        self::assertSame($dignityId, $entry['details']['target_mood_id']);
        self::assertStringContainsString('fulllograw1 played Encouragement', $entry['description']);
        self::assertStringContainsString('target mood: Dignity', $entry['description']);
    }

    /**
     * Every seated player sees the exact same full log, the same way
     * recentEvents() itself already applies no per-viewer filtering --
     * confirmed here against a Malice cascade specifically (see
     * testRecentEventsDescribeEveryMoodMaliceDiscardsIncludingTheColorCascade())
     * since that event's own description is exactly the kind of
     * multi-segment, semicolon-joined text the frontend's "bulleted list
     * headed by the first item" rendering (buildLogEntryContent() in
     * game.js) exists for.
     */
    public function testFullEventLogIsIdenticalRegardlessOfViewerAndIncludesMultiSegmentDescriptions(): void
    {
        $u1 = $this->insertUser('fulllogmalice1');
        $u2 = $this->insertUser('fulllogmalice2');
        $u3 = $this->insertUser('fulllogmalice3');

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

        // fullEventLog() itself takes no viewer argument at all -- unlike
        // getState()'s own per-viewer 'recent_events' field -- so there's
        // nothing to vary per user here; this just documents that fact by
        // calling it once and using the one result to cover both checks.
        $fullLog = $this->games->fullEventLog($gameId);

        $resolvedEntry = null;
        foreach ($fullLog as $entry) {
            if ($entry['event_type'] === 'pending_decision_resolved') {
                $resolvedEntry = $entry;
                break;
            }
        }
        self::assertNotNull($resolvedEntry, 'no pending_decision_resolved event found');
        self::assertStringContainsString('; ', $resolvedEntry['description'], 'a multi-part, semicolon-joined description');
        self::assertStringContainsString('Discipline moved from play to the discard pile', $resolvedEntry['description']);
        self::assertStringContainsString('Charity moved from play to the discard pile', $resolvedEntry['description']);
        self::assertStringContainsString('Dignity moved from play to the discard pile', $resolvedEntry['description']);
    }

    // -- Shared deck view (issue #197) ----------------------------------------

    /**
     * A shared-deck game's deck is every card actually dealt (across every
     * zone -- game_cards rows are created once, at startGame() time, and
     * never added to afterward, see BoardStateRepository's own docblock),
     * sorted white/blue/black/red/green, then alphabetically by name
     * within a color -- not whatever order the shuffle happened to deal
     * them in. deckType is pinned to 'one_of_each' so the expected total
     * (133, one of every printed card) is exact regardless of
     * createGame()'s own default, same reasoning as
     * testCreateGameAndStartGameDealsCardsAndBeginsFirstRound().
     */
    public function testViewSharedDeckReturnsEveryDealtCardSortedByColorThenName(): void
    {
        $creator = $this->insertUser('sharedviewsort1');
        $bob = $this->insertUser('sharedviewsort2');

        $gameId = $this->games->createGame($creator, [$creator, $bob], deckType: 'one_of_each');
        $this->games->startGame($gameId);

        $cards = $this->games->viewSharedDeck($gameId);
        self::assertCount(133, $cards, 'every printed card, once');

        $expectedColorOrder = ['white', 'blue', 'black', 'red', 'green'];
        $colorIndexes = array_map(
            static fn (array $c) => array_search($c['color'], $expectedColorOrder, true),
            $cards
        );
        self::assertNotContains(false, $colorIndexes, 'every card has one of the five printed colors');
        $sortedColorIndexes = $colorIndexes;
        sort($sortedColorIndexes);
        self::assertSame(
            $sortedColorIndexes,
            $colorIndexes,
            'colors appear white/blue/black/red/green, contiguously grouped -- no color interleaved out of place'
        );
        self::assertCount(5, array_unique($colorIndexes), 'all five colors actually represented, none collapsed');

        // Within a color, alphabetical by name -- checked against every
        // white card this game dealt ('one_of_each' guarantees every
        // color is fully represented), not a hand-picked pair, so this
        // would catch a comparator bug that only misorders some of them.
        $whiteNames = array_values(array_map(
            static fn (array $c) => $c['name'],
            array_filter($cards, static fn (array $c) => $c['color'] === 'white')
        ));
        $sortedWhiteNames = $whiteNames;
        sort($sortedWhiteNames, SORT_STRING);
        self::assertSame($sortedWhiteNames, $whiteNames);
    }

    /**
     * Two copies of the same catalog card (e.g. a jceddys_75 deck's own
     * up-to-2-copies slots -- see randomCardIdsWithCopyLimit()) appear as
     * two separate entries, not collapsed into one with a count -- the
     * same convention openDeckView() already uses for a saved decklist's
     * cards in game.js.
     */
    public function testViewSharedDeckKeepsDuplicateCopiesAsSeparateEntries(): void
    {
        $creator = $this->insertUser('sharedviewdup1');
        $bob = $this->insertUser('sharedviewdup2');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, deck_type, status, created_by_user_id, wins_needed) VALUES ('standard', 'jceddys_75', 'in_progress', :created_by, 3)"
        );
        $stmt->execute(['created_by' => $creator]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $creator, 0);
        $this->insertGamePlayer($gameId, $bob, 1);

        $this->insertGameCard($gameId, 3, 'hand', $p1); // Charity, white
        $this->insertGameCard($gameId, 3, 'deck', null, 0); // a second Charity
        $this->insertGameCard($gameId, 100, 'discard'); // Recklessness, red

        $cards = $this->games->viewSharedDeck($gameId);
        $names = array_map(static fn (array $c) => $c['name'], $cards);
        self::assertSame(['Charity', 'Charity', 'Recklessness'], $names);
    }

    /**
     * custom_duel and the three draft-based deck_types each give every
     * player their own separate deck -- there's no single "the deck" for
     * viewSharedDeck() to show (see isSharedDeckType()'s own docblock).
     */
    public function testViewSharedDeckRejectsADeckTypeWithNoSingleSharedDeck(): void
    {
        $u1 = $this->insertUser('sharedviewreject1');
        $u2 = $this->insertUser('sharedviewreject2');

        $gameId = $this->games->createGame(
            $u1,
            [$u1, $u2],
            format: 'duel',
            deckType: 'custom_duel',
            duelDeckRules: ['preset' => 'structure'],
        );

        $this->expectException(GameStateException::class);
        $this->games->viewSharedDeck($gameId);
    }

    /**
     * A still-'waiting' game has no game_cards rows at all yet -- its
     * deck doesn't exist until startGame() actually deals it -- so
     * there's nothing for viewSharedDeck() to show.
     */
    public function testViewSharedDeckRejectsAGameThatHasntStartedYet(): void
    {
        $creator = $this->insertUser('sharedviewwaiting1');
        $bob = $this->insertUser('sharedviewwaiting2');

        $gameId = $this->games->createGame($creator, [$creator, $bob], deckType: 'one_of_each');

        $this->expectException(GameStateException::class);
        $this->games->viewSharedDeck($gameId);
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
            'u1' => $u1,
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

        // The round_scored event itself calls out who Hurt Feelings went to,
        // not just the round_2 row -- see GameService::describeRoundScored().
        $events = $this->games->getState($gameId, $u1)['recent_events'];
        self::assertStringContainsString('p3 has Hurt Feelings next round', $events[0]['description']);

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

        // The round_scored log entry itself calls out the override --
        // otherwise it'd only be inferable once round 2 actually starts.
        $events = $this->games->getState($gameId, $u1)['recent_events'];
        self::assertStringContainsString(
            "honor3 goes first next round instead of the round's winner",
            $events[0]['description'],
        );
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

        $events = $this->games->getState($gameId, $u1)['recent_events'];
        self::assertStringContainsString('awe2 goes first next round', $events[0]['description']);
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
     * Regression test: computeFreshGrants() used to push a bare `null`
     * for Hope's/Stubbornness's/a banked Generosity-Joy grant, identical
     * to the base allowance's own `null` entries -- describePlayGrant()
     * can't tell those apart, so the bonus play silently read "Your
     * normal turn" instead of naming Hope as its source.
     */
    public function testPlayGrantDescriptionNamesHopeInsteadOfReadingAsANormalTurn(): void
    {
        $u1 = $this->insertUser('hopegrantname1');
        $u2 = $this->insertUser('hopegrantname2');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('standard', 'in_progress', :created_by, 3)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $p2 = $this->insertGamePlayer($gameId, $u2, 1);

        $this->insertGameCard($gameId, 124, 'in_play', $p1); // real Hope
        $this->insertGameRound($gameId, 1, $p2, $p2, 1);

        // p2 passes -- the turn advances to p1, whose fresh grants should
        // now include Hope's perpetual bonus.
        $this->games->pass($gameId, $p2);

        $playGrants = $this->games->getState($gameId, $u1)['round']['play_grants'];
        self::assertCount(2, $playGrants);
        self::assertSame('Your normal turn', $playGrants[0]['description']);
        self::assertSame('An extra play from Hope', $playGrants[1]['description']);
        self::assertSame($p1, $this->games->gamePlayerIdFor($gameId, $u1)); // sanity: p1 is who the grant belongs to
        self::assertSame('Hope', $playGrants[1]['source_card_name']);
    }

    /**
     * Regression test: computeFreshGrants() used to look up only the
     * *first* in-play mood matching a given perpetual-grant effect key
     * (effectiveSourceCardId(), singular) -- so two independent real Hopes
     * (e.g. a duplicate printed card across a duel game's two separate
     * decks, or an intentionally duplicate-including custom deck) only
     * ever granted one extra play at the start of a future turn instead
     * of two, even though each Hope is its own physical card and
     * MoodPlayService's own same-turn bonus already correctly grants one
     * per Hope actually played. effectiveSourceCardIds() (plural) fixes
     * this by returning every qualifying mood, not just the first.
     */
    public function testTwoIndependentHopesEachGrantTheirOwnPerpetualExtraPlay(): void
    {
        $u1 = $this->insertUser('doublehope1');
        $u2 = $this->insertUser('doublehope2');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('standard', 'in_progress', :created_by, 3)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $p2 = $this->insertGamePlayer($gameId, $u2, 1);

        $hope1Id = $this->insertGameCard($gameId, 124, 'in_play', $p1); // Hope #1
        $hope2Id = $this->insertGameCard($gameId, 124, 'in_play', $p1); // Hope #2, independent physical card
        $this->insertGameRound($gameId, 1, $p2, $p2, 1);

        // p2 passes -- the turn advances to p1, whose fresh grants should
        // now include BOTH Hopes' perpetual bonuses.
        $this->games->pass($gameId, $p2);

        $playGrants = $this->games->getState($gameId, $u1)['round']['play_grants'];
        self::assertCount(3, $playGrants); // base allowance + 2 independent Hope bonuses
        self::assertSame('Your normal turn', $playGrants[0]['description']);
        self::assertSame('An extra play from Hope', $playGrants[1]['description']);
        self::assertSame('An extra play from Hope', $playGrants[2]['description']);
        self::assertSame(
            [$hope1Id, $hope2Id],
            [$playGrants[1]['source_card_id'], $playGrants[2]['source_card_id']],
        );
    }

    /**
     * Hope's own perpetual grant is lost outright -- not merely kept but
     * un-attributed -- if that specific Hope leaves play before the player
     * gets around to using the play it granted. Bravado's own effect
     * ("you may put one of your other moods into the discard pile; if you
     * do, you may play an additional mood this turn") is the simplest real
     * card that discards a player's own in-play mood as part of playing a
     * different card, making it a convenient way to remove Hope from play
     * mid-turn without needing a dedicated opponent-decision or scoring
     * flow just to set this scenario up.
     */
    public function testHopesPerpetualGrantIsLostIfHopeIsDiscardedBeforeItsUsed(): void
    {
        $u1 = $this->insertUser('hopelost1');
        $u2 = $this->insertUser('hopelost2');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('standard', 'in_progress', :created_by, 3)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $p2 = $this->insertGamePlayer($gameId, $u2, 1);

        $hopeId = $this->insertGameCard($gameId, 124, 'in_play', $p1); // Hope, already in play
        $bravadoId = $this->insertGameCard($gameId, 84, 'hand', $p1); // Bravado, in p1's hand
        $this->insertGameRound($gameId, 1, $p2, $p2, 1);

        // p2 passes -- the turn advances to p1, whose fresh grants now
        // include Hope's perpetual bonus (base allowance + Hope = 2).
        $this->games->pass($gameId, $p2);
        self::assertSame(2, (int) $this->fetchRound($gameId)['plays_remaining']);

        // p1 plays Bravado, discarding their own Hope as its cost -- this
        // consumes the base allowance to play Bravado itself, discards
        // Hope (destroying its now-stale grant), then grants Bravado's own
        // unconditional bonus. Net: 2 -> 1, not 2 -> 2 -- Hope's own grant
        // doesn't survive its own removal from play.
        $this->games->playMood($gameId, $p1, $bravadoId, ['discard_mood_id' => $hopeId]);

        $stateAfter = $this->games->getState($gameId, $u1);
        self::assertSame(1, (int) $this->fetchRound($gameId)['plays_remaining']);
        $playGrants = $stateAfter['round']['play_grants'];
        self::assertCount(1, $playGrants);
        self::assertSame('An extra play from Bravado', $playGrants[0]['description']);

        // The event log must say what happened to Hope's own bonus play
        // instead of just letting plays_remaining quietly drop by one --
        // otherwise a player who expected 2 plays this turn has no way to
        // tell whether that's a bug or working as intended.
        self::assertStringContainsString(
            "lost an extra play from Hope -- its source left play before it was used",
            $stateAfter['recent_events'][0]['description'],
        );
    }

    /**
     * Two independent real Hopes (see testTwoIndependentHopesEachGrantTheirOwnPerpetualExtraPlay
     * above) each grant their own perpetual bonus, on top of the base
     * allowance -- 3 distinct usable grants for any ordinary hand card, so
     * GameService::serializeCard() should offer an explicit
     * 'grant_source_card_id' choice field naming all 3, tagged 0/hope1Id/
     * hope2Id per BoardState::usableGrants()'s own sentinel convention.
     */
    public function testGrantSourceCardIdChoiceFieldOffersEachDistinctUsableGrant(): void
    {
        $u1 = $this->insertUser('grantchoice1');
        $u2 = $this->insertUser('grantchoice2');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('standard', 'in_progress', :created_by, 3)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $p2 = $this->insertGamePlayer($gameId, $u2, 1);

        $hope1Id = $this->insertGameCard($gameId, 124, 'in_play', $p1); // Hope #1
        $hope2Id = $this->insertGameCard($gameId, 124, 'in_play', $p1); // Hope #2, independent physical card
        $complacencyId = $this->insertGameCard($gameId, 5, 'hand', $p1); // Complacency, no abilities of its own
        $this->insertGameRound($gameId, 1, $p2, $p2, 1);

        // p2 passes -- the turn advances to p1, whose fresh grants now
        // include the base allowance plus BOTH Hopes' perpetual bonuses.
        $this->games->pass($gameId, $p2);

        $hand = $this->games->getState($gameId, $u1)['you']['hand'];
        $complacency = null;
        foreach ($hand as $card) {
            if ($card['card_id'] === $complacencyId) {
                $complacency = $card;
            }
        }
        self::assertNotNull($complacency);

        $grantField = null;
        foreach ($complacency['choice_fields'] as $field) {
            if ($field['key'] === 'grant_source_card_id') {
                $grantField = $field;
            }
        }
        self::assertNotNull($grantField);
        self::assertSame('grant_choice', $grantField['type']);
        self::assertFalse($grantField['required']);
        self::assertSame(
            ['value' => 0, 'label' => 'Your normal turn'],
            $grantField['options'][0],
        );
        self::assertSame(
            ['value' => $hope1Id, 'label' => 'An extra play from Hope'],
            $grantField['options'][1],
        );
        self::assertSame(
            ['value' => $hope2Id, 'label' => 'An extra play from Hope'],
            $grantField['options'][2],
        );
    }

    /**
     * The card-detail-dialog indicator's own backing data: each Hope
     * remains "armed" (has_unused_play_grant true) until the specific play
     * it granted is actually spent -- choosing one via grant_source_card_id
     * clears only that one Hope's flag, leaving the other (and the base
     * allowance, which has no card of its own to flag) untouched.
     */
    public function testHasUnusedPlayGrantClearsOnlyForTheHopeWhoseGrantWasSpent(): void
    {
        $u1 = $this->insertUser('unusedgrant1');
        $u2 = $this->insertUser('unusedgrant2');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('standard', 'in_progress', :created_by, 3)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $p2 = $this->insertGamePlayer($gameId, $u2, 1);

        $hope1Id = $this->insertGameCard($gameId, 124, 'in_play', $p1);
        $hope2Id = $this->insertGameCard($gameId, 124, 'in_play', $p1);
        $complacencyId = $this->insertGameCard($gameId, 5, 'hand', $p1);
        $this->insertGameRound($gameId, 1, $p2, $p2, 1);

        $this->games->pass($gameId, $p2);

        $unusedGrantFlagFor = function (array $state, int $cardId): bool {
            foreach ($state['in_play'] as $card) {
                if ($card['card_id'] === $cardId) {
                    return $card['has_unused_play_grant'];
                }
            }
            throw new \RuntimeException("card {$cardId} not found in in_play");
        };

        $before = $this->games->getState($gameId, $u1);
        self::assertTrue($unusedGrantFlagFor($before, $hope1Id));
        self::assertTrue($unusedGrantFlagFor($before, $hope2Id));

        $this->games->playMood($gameId, $p1, $complacencyId, ['grant_source_card_id' => $hope1Id]);

        $after = $this->games->getState($gameId, $u1);
        self::assertFalse($unusedGrantFlagFor($after, $hope1Id)); // spent
        self::assertTrue($unusedGrantFlagFor($after, $hope2Id)); // still outstanding
    }

    /**
     * Contrast with the test above: Stubbornness's own perpetual grant is
     * deliberately NOT tagged 'requiresSourceInPlay' (see
     * GameService::computeFreshGrants()'s own docblock), so it persists
     * for the rest of the turn even if Stubbornness itself is discarded
     * before the player uses the play it granted -- unlike Hope/Grace.
     */
    public function testStubbornnessPerpetualGrantSurvivesStubbornnessBeingDiscarded(): void
    {
        $u1 = $this->insertUser('stubborngrant1');
        $u2 = $this->insertUser('stubborngrant2');
        $u3 = $this->insertUser('stubborngrant3');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('standard', 'in_progress', :created_by, 3)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $p2 = $this->insertGamePlayer($gameId, $u2, 1);
        $p3 = $this->insertGamePlayer($gameId, $u3, 2);

        $stubbornnessId = $this->insertGameCard($gameId, 102, 'in_play', $p1); // Stubbornness, already in play
        $bravadoId = $this->insertGameCard($gameId, 84, 'hand', $p1); // Bravado, in p1's hand
        // p2 has more moods in play than p1 -- Stubbornness's own condition.
        $this->insertGameCard($gameId, 5, 'in_play', $p2);
        $this->insertGameCard($gameId, 27, 'in_play', $p2);
        $this->insertGameRound($gameId, 1, $p3, $p3, 1);

        // p3 passes to p1's turn (skipping p2 would be wrong seat order --
        // seat order is p1=0, p2=1, p3=2, so first_player p3 rotates to p1
        // next). p1's fresh grants now include Stubbornness's bonus.
        $this->games->pass($gameId, $p3);
        self::assertSame(2, (int) $this->fetchRound($gameId)['plays_remaining']);

        // p1 plays Bravado, discarding their own Stubbornness as its cost.
        $this->games->playMood($gameId, $p1, $bravadoId, ['discard_mood_id' => $stubbornnessId]);

        // Net: 2 -> 2 (Bravado's own new grant replaces the one consumed
        // to play it), NOT 2 -> 1 -- Stubbornness's own grant survives its
        // own discard, unlike Hope's/Grace's in the test above.
        self::assertSame(2, (int) $this->fetchRound($gameId)['plays_remaining']);
    }

    /**
     * The exact bug report this fix addresses: a Creativity copying Hope
     * grants the extra play (already correctly, via
     * BoardState::countMoodsInPlayWithEffectiveKey()'s copy-aware
     * effectiveCardId() lookup), but its description named no source at
     * all before this fix -- it should read as coming from "Hope" (the
     * copied identity), exactly the way the in-play list itself already
     * displays that Creativity as Hope everywhere else.
     */
    public function testPlayGrantDescriptionNamesHopeWhenGrantedViaACreativityCopy(): void
    {
        $u1 = $this->insertUser('hopecreativity1');
        $u2 = $this->insertUser('hopecreativity2');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('standard', 'in_progress', :created_by, 3)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $p2 = $this->insertGamePlayer($gameId, $u2, 1);

        // The real Hope belongs to p2 (Creativity can copy any in-play
        // mood, not just your own) -- p1's own bonus can only come from
        // their Creativity, so there's no ambiguity about which physical
        // card the grant is actually attributed to.
        $hopeId = $this->insertGameCard($gameId, 124, 'in_play', $p2); // real Hope, owned by p2
        $creativityId = $this->insertGameCard($gameId, 32, 'in_play', $p1); // p1's Creativity, copying p2's Hope
        $this->pdo->prepare('UPDATE game_cards SET copied_card_id = :copied_card_id WHERE id = :id')
            ->execute(['copied_card_id' => $hopeId, 'id' => $creativityId]);
        $this->insertGameRound($gameId, 1, $p2, $p2, 1);

        $this->games->pass($gameId, $p2);

        $playGrants = $this->games->getState($gameId, $u1)['round']['play_grants'];
        self::assertCount(2, $playGrants);
        self::assertSame('Your normal turn', $playGrants[0]['description']);
        self::assertSame('An extra play from Hope', $playGrants[1]['description']);
        self::assertSame('Hope', $playGrants[1]['source_card_name']);
        // Resolves to the copied Hope's own instance id -- same
        // effectiveCardId() translation serializeCard() already uses so
        // this Creativity displays (and here, is named) as "Hope"
        // everywhere, not as "Creativity".
        self::assertSame($hopeId, $playGrants[1]['source_card_id']);
    }

    public function testPlayGrantDescriptionNamesGraceWithItsDiscardRestriction(): void
    {
        $u1 = $this->insertUser('gracegrantname1');
        $u2 = $this->insertUser('gracegrantname2');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('standard', 'in_progress', :created_by, 3)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $p2 = $this->insertGamePlayer($gameId, $u2, 1);

        $this->insertGameCard($gameId, 121, 'in_play', $p1); // real Grace
        $this->insertGameRound($gameId, 1, $p2, $p2, 1);

        $this->games->pass($gameId, $p2);

        $playGrants = $this->games->getState($gameId, $u1)['round']['play_grants'];
        self::assertCount(2, $playGrants);
        self::assertSame(
            'An extra play from Grace from the discard pile (must share a color with one of your moods)',
            $playGrants[1]['description'],
        );
        self::assertSame('Grace', $playGrants[1]['source_card_name']);
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

        // Regression: the bonus grant used to be indistinguishable from
        // the base allowance (both a bare `null`), reading as "Your
        // normal turn" instead of naming Stubbornness.
        $playGrants = $this->games->getState($gameId, $u1)['round']['play_grants'];
        self::assertSame('Your normal turn', $playGrants[0]['description']);
        self::assertSame('An extra play from Stubbornness', $playGrants[1]['description']);
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

    /**
     * Regression test: the 'pending_decision_resolved' event's own
     * acting_game_player_id is the RESPONDER (p2, who reveals a card) --
     * describeEvent()'s grants_created segment used to attribute the
     * resulting grant to that same $actor, incorrectly crediting p2 with
     * an extra play that's actually p1's own (p1 played Intimidation and
     * is still mid-turn; p2 never gets a turn out of responding). See
     * describeEvent()'s own 'initiating_game_player_id' handling.
     */
    public function testIntimidationsGrantIsAttributedToWhoeverPlayedItNotTheRespondingTarget(): void
    {
        $u1 = $this->insertUser('intimgrantevt1');
        $u2 = $this->insertUser('intimgrantevt2');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('standard', 'in_progress', :created_by, 3)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $p2 = $this->insertGamePlayer($gameId, $u2, 1);

        $intimidationId = $this->insertGameCard($gameId, 67, 'hand', $p1); // Intimidation
        $card3Id = $this->insertGameCard($gameId, 3, 'hand', $p2); // p2's only card -- guaranteed to be the one revealed
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $this->games->playMood($gameId, $p1, $intimidationId, ['target_player_id' => $p2]);
        $this->games->respondToDecision($gameId, $p2, ['revealed_card_id' => $card3Id]);

        $events = $this->games->getState($gameId, $u1)['recent_events'];
        $resolvedEvent = null;
        foreach ($events as $event) {
            if (str_contains($event['description'], 'was granted')) {
                $resolvedEvent = $event;
                break;
            }
        }
        self::assertNotNull($resolvedEvent, 'no "was granted" event found');
        self::assertStringContainsString('intimgrantevt1 was granted', $resolvedEvent['description'], 'p1 played Intimidation and is still mid-turn -- the grant is theirs, not the responding target\'s');
        self::assertStringNotContainsString('intimgrantevt2 was granted', $resolvedEvent['description']);
        // 'initiating_game_player_id' rides along in $details purely for
        // this attribution fix -- it must never leak into the choice
        // summary as an ordinary-looking "initiating game player: ..." clause.
        self::assertStringNotContainsString('initiating', $resolvedEvent['description']);

        $fullLogEntry = null;
        foreach ($this->games->fullEventLog($gameId) as $entry) {
            if ($entry['event_type'] === 'pending_decision_resolved') {
                $fullLogEntry = $entry;
                break;
            }
        }
        self::assertNotNull($fullLogEntry);
        self::assertSame($p1, $fullLogEntry['details']['initiating_game_player_id']);
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
     * Disillusionment's own "may" -- respondToDecision() is called with an
     * empty choices array (no 'chosen_color_*' key at all), the same shape
     * a real blank/"(none)" widget submission produces (see
     * buildChoicesFromFields() in game.js, which omits the key entirely
     * rather than sending an empty string), for every player in the queue
     * except one. Declining must resolve cleanly (no InvalidChoiceException)
     * and contribute no color at all.
     */
    public function testDisillusionmentAllowsEveryPlayerToDeclineThroughTheRealHttpServiceLayer(): void
    {
        $u1 = $this->insertUser('disildec1');
        $u2 = $this->insertUser('disildec2');
        $u3 = $this->insertUser('disildec3');

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

        // p2 and p3 both decline outright; only p1 (the acting player
        // themselves) actually picks a color.
        $result1 = $this->games->respondToDecision($gameId, $p2, []);
        self::assertTrue($result1['pending_decision'] ?? false);

        $result2 = $this->games->respondToDecision($gameId, $p3, []);
        self::assertTrue($result2['pending_decision'] ?? false);

        $result3 = $this->games->respondToDecision($gameId, $p1, ['chosen_color_' . $p1 => 'blue']);
        self::assertArrayNotHasKey('pending_decision', $result3);

        $registry = DefaultEffectRegistry::build();
        $state = (new BoardStateRepository($registry))->load($gameId);
        self::assertTrue($state->isInPlay($disillusionmentId));
        self::assertTrue($state->isInPlay($disciplineId)); // white, not chosen -- survives
        self::assertTrue($state->isInPlay($ambitionId)); // black, not chosen -- survives
        self::assertFalse($state->isInPlay($anxietyId)); // blue, chosen by p1
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

    /**
     * Enthusiasm/Passion's own scoring-time pending_decision_created event
     * needs different phrasing than every other pending_decision_created
     * event -- the card triggering it has already been sitting in play
     * since some earlier turn, not just played this instant, so
     * "{actor} played Enthusiasm ..., waiting on a response" (the template
     * every other one of these events uses) would misleadingly read as
     * though the player just played a second copy of the card.
     */
    public function testEnthusiasmsScoringDecisionEventDoesNotReadAsASecondPlay(): void
    {
        $u1 = $this->insertUser('enthlog1');
        $u2 = $this->insertUser('enthlog2');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('standard', 'in_progress', :created_by, 3)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $p2 = $this->insertGamePlayer($gameId, $u2, 1);

        $this->insertGameCard($gameId, 116, 'in_play', $p1); // Enthusiasm
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $this->games->pass($gameId, $p1);
        $this->games->pass($gameId, $p2);

        $description = $this->games->getState($gameId, $u1)['recent_events'][0]['description'];

        self::assertStringNotContainsString('played Enthusiasm', $description);
        self::assertSame("Enthusiasm's scoring effect triggered, waiting on a response from enthlog1", $description);
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
            new UserDecklistService(
                new UserDecklistRepository(),
                new FriendshipService(new UserRepository(), new FriendshipRepository()),
            ),
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

    // -- Open Team Play (format 'team') -----------------------------------

    private function insertTeamGamePlayer(int $gameId, int $userId, int $seatOrder, int $teamId): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO game_players (game_id, user_id, seat_order, team_id) VALUES (:game_id, :user_id, :seat_order, :team_id)'
        );
        $stmt->execute(['game_id' => $gameId, 'user_id' => $userId, 'seat_order' => $seatOrder, 'team_id' => $teamId]);

        return (int) $this->pdo->lastInsertId();
    }

    private function insertTeamGameRound(int $gameId, int $roundNumber, int $firstGamePlayerId): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO game_rounds (game_id, round_number, first_game_player_id, current_turn_game_player_id, plays_remaining, status)
             VALUES (:game_id, :round_number, :first_player, NULL, 0, 'in_progress')"
        );
        $stmt->execute(['game_id' => $gameId, 'round_number' => $roundNumber, 'first_player' => $firstGamePlayerId]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @param int[] $candidateGamePlayerIds */
    private function insertTeamDecision(int $gameId, int $roundId, int $teamId, string $decisionType, array $candidateGamePlayerIds): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO game_team_decisions (game_id, game_round_id, team_id, decision_type, candidate_game_player_ids)
             VALUES (:game_id, :round_id, :team_id, :decision_type, :candidates)'
        );
        $stmt->execute([
            'game_id' => $gameId,
            'round_id' => $roundId,
            'team_id' => $teamId,
            'decision_type' => $decisionType,
            'candidates' => json_encode($candidateGamePlayerIds),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function fetchOpenTeamDecision(int $gameId): array|false
    {
        $stmt = $this->pdo->prepare('SELECT * FROM game_team_decisions WHERE game_id = :game_id AND resolved_at IS NULL LIMIT 1');
        $stmt->execute(['game_id' => $gameId]);

        return $stmt->fetch();
    }

    /**
     * Team 0 = p1/p2 (seats 0/1), Team 1 = p3/p4 (seats 2/3) -- adjacent
     * seating per "Open Team Play" in php-app/README.md. Round 1 starts
     * exactly the way startGame() would leave it: current_turn_game_player_id
     * NULL, team_turn_1/2 unset, and an already-open turn_order decision
     * for team 0 (the round's own first_game_player_id, p1, is team 0's
     * representative). Each player's one hand card is one of the five
     * vanilla, no-ability, flat-4-value commons (migration 0003's own
     * catalog comment), so nothing here can trigger a card effect --
     * keeping the round's score math (team totals, tie-breaks)
     * unambiguous.
     *
     * @return array{gameId:int, roundId:int, p1:int, p2:int, p3:int, p4:int, complacencyId:int, indifferenceId:int, apathyId:int, boredomId:int}
     */
    private function buildTeamFixture(): array
    {
        $u1 = $this->insertUser('team1p1');
        $u2 = $this->insertUser('team1p2');
        $u3 = $this->insertUser('team2p1');
        $u4 = $this->insertUser('team2p2');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('team', 'in_progress', :created_by, 2)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertTeamGamePlayer($gameId, $u1, 0, 0);
        $p2 = $this->insertTeamGamePlayer($gameId, $u2, 1, 0);
        $p3 = $this->insertTeamGamePlayer($gameId, $u3, 2, 1);
        $p4 = $this->insertTeamGamePlayer($gameId, $u4, 3, 1);

        $complacencyId = $this->insertGameCard($gameId, 5, 'hand', $p1); // white, value 4
        $indifferenceId = $this->insertGameCard($gameId, 44, 'hand', $p2); // blue, value 4
        $apathyId = $this->insertGameCard($gameId, 55, 'hand', $p3); // black, value 4
        $boredomId = $this->insertGameCard($gameId, 83, 'hand', $p4); // red, value 4
        $this->insertGameCard($gameId, 27, 'deck', null, 0);
        $this->insertGameCard($gameId, 54, 'deck', null, 1);

        $roundId = $this->insertTeamGameRound($gameId, 1, $p1);
        $this->insertTeamDecision($gameId, $roundId, 0, 'turn_order', [$p1, $p2]);

        return [
            'gameId' => $gameId,
            'roundId' => $roundId,
            'p1' => $p1,
            'p2' => $p2,
            'p3' => $p3,
            'p4' => $p4,
            'complacencyId' => $complacencyId,
            'indifferenceId' => $indifferenceId,
            'apathyId' => $apathyId,
            'boredomId' => $boredomId,
        ];
    }

    public function testCreateGameTeamFormatRequiresExactlyFourPlayers(): void
    {
        $u1 = $this->insertUser('c1');
        $u2 = $this->insertUser('c2');
        $u3 = $this->insertUser('c3');

        $this->expectException(GameStateException::class);
        $this->expectExceptionMessage('exactly 4 players');
        $this->games->createGame($u1, [$u1, $u2, $u3], 'team', 3, 'structure', null, null, $u2);
    }

    public function testCreateGameTeamFormatRequiresPartnerUserId(): void
    {
        $u1 = $this->insertUser('c1');
        $u2 = $this->insertUser('c2');
        $u3 = $this->insertUser('c3');
        $u4 = $this->insertUser('c4');

        $this->expectException(GameStateException::class);
        $this->expectExceptionMessage('partner');
        $this->games->createGame($u1, [$u1, $u2, $u3, $u4], 'team', 3, 'structure', null, null, null);
    }

    public function testCreateGameTeamFormatSeatsPartnersAdjacentAndAssignsTeamIds(): void
    {
        $u1 = $this->insertUser('c1');
        $u2 = $this->insertUser('c2');
        $u3 = $this->insertUser('c3');
        $u4 = $this->insertUser('c4');

        $gameId = $this->games->createGame($u1, [$u1, $u2, $u3, $u4], 'team', 3, 'structure', null, null, $u3);

        $stmt = $this->pdo->prepare(
            'SELECT user_id, seat_order, team_id FROM game_players WHERE game_id = :game_id ORDER BY seat_order ASC'
        );
        $stmt->execute(['game_id' => $gameId]);
        $rows = $stmt->fetchAll();

        self::assertSame($u1, (int) $rows[0]['user_id']);
        self::assertSame(0, (int) $rows[0]['team_id']);
        // The creator's chosen partner (u3) sits next to them, not
        // wherever they happened to fall in opponent_user_ids order.
        self::assertSame($u3, (int) $rows[1]['user_id']);
        self::assertSame(0, (int) $rows[1]['team_id']);
        self::assertSame($u2, (int) $rows[2]['user_id']);
        self::assertSame(1, (int) $rows[2]['team_id']);
        self::assertSame($u4, (int) $rows[3]['user_id']);
        self::assertSame(1, (int) $rows[3]['team_id']);
    }

    public function testCreateGameTeamFormatRejectsPowerDeckType(): void
    {
        $u1 = $this->insertUser('c1');
        $u2 = $this->insertUser('c2');
        $u3 = $this->insertUser('c3');
        $u4 = $this->insertUser('c4');

        $this->expectException(GameStateException::class);
        $this->expectExceptionMessage('45-card minimum');
        $this->games->createGame($u1, [$u1, $u2, $u3, $u4], 'team', 3, 'power', null, null, $u2);
    }

    /**
     * End-to-end: both teams' turn_order propose/confirm (including a
     * rejected proposal being sent back to 'propose'), the two forced
     * turns, team-aggregated scoring (deliberately a tie, to also cover
     * the "whichever team played first wins ties" rule), the losing
     * team's shared-draw propose/confirm, and the next round's own
     * turn_order decision being opened for the team that just won.
     */
    public function testFullTeamRoundCycleWithProposeConfirmTurnOrderAndSharedDraw(): void
    {
        [
            'gameId' => $gameId,
            'p1' => $p1,
            'p2' => $p2,
            'p3' => $p3,
            'p4' => $p4,
            'complacencyId' => $complacencyId,
            'apathyId' => $apathyId,
        ] = $this->buildTeamFixture();

        $round = $this->fetchRound($gameId);
        self::assertNull($round['current_turn_game_player_id']);

        $decision = $this->fetchOpenTeamDecision($gameId);
        self::assertSame(0, (int) $decision['team_id']);
        self::assertSame('turn_order', $decision['decision_type']);
        self::assertSame('propose', $decision['phase']);

        // p1 proposes themselves; p1 can't also confirm their own proposal.
        $this->games->proposeTeamDecision($gameId, $p1, $p1);
        try {
            $this->games->confirmTeamDecision($gameId, $p1, true);
            self::fail('Expected a GameStateException for confirming your own proposal');
        } catch (GameStateException $e) {
            self::assertStringContainsString("can't also confirm", $e->getMessage());
        }

        // p2 rejects -- sends it back to 'propose' with no proposal recorded.
        $this->games->confirmTeamDecision($gameId, $p2, false);
        $decision = $this->fetchOpenTeamDecision($gameId);
        self::assertSame('propose', $decision['phase']);
        self::assertNull($decision['proposer_game_player_id']);
        self::assertNull($decision['proposed_game_player_id']);

        // p2 proposes p1 goes first this round; p1 confirms.
        $this->games->proposeTeamDecision($gameId, $p2, $p1);
        $this->games->confirmTeamDecision($gameId, $p1, true);

        $round = $this->fetchRound($gameId);
        self::assertSame($p1, (int) $round['team_turn_1_game_player_id']);
        self::assertSame($p1, (int) $round['current_turn_game_player_id']);

        // Team 1's own turn_order decision opens immediately (there's no
        // single player to wait on a play/pass from in between).
        $decision = $this->fetchOpenTeamDecision($gameId);
        self::assertSame(1, (int) $decision['team_id']);
        self::assertSame('turn_order', $decision['decision_type']);

        // Turn 1: p1 plays Complacency (value 4). Freezes the round --
        // team 1's own choice is already pending, not a forced next player.
        $this->games->playMood($gameId, $p1, $complacencyId, []);
        $round = $this->fetchRound($gameId);
        self::assertNull($round['current_turn_game_player_id']);

        // Team 1 proposes/confirms p3 for turn 2.
        $this->games->proposeTeamDecision($gameId, $p3, $p3);
        $this->games->confirmTeamDecision($gameId, $p4, true);
        $round = $this->fetchRound($gameId);
        self::assertSame($p3, (int) $round['team_turn_2_game_player_id']);
        self::assertSame($p3, (int) $round['current_turn_game_player_id']);
        self::assertFalse($this->fetchOpenTeamDecision($gameId), 'no decision needed for the two forced remaining turns');

        // Turn 2: p3 plays Apathy (value 4). Turn 3 is forced: team 0's
        // other member, p2.
        $this->games->playMood($gameId, $p3, $apathyId, []);
        $round = $this->fetchRound($gameId);
        self::assertSame($p2, (int) $round['current_turn_game_player_id']);

        // Turn 3: p2 passes. Turn 4 is forced: team 1's other member, p4.
        $this->games->pass($gameId, $p2);
        $round = $this->fetchRound($gameId);
        self::assertSame($p4, (int) $round['current_turn_game_player_id']);

        // Turn 4: p4 passes -- round scores. Team 0: 4 (p1) + 0 (p2) = 4.
        // Team 1: 4 (p3) + 0 (p4) = 4 -- a tie, broken by whichever team
        // played first (team 0, since p1 -- team 0 -- was this round's
        // first_game_player_id).
        $result = $this->games->pass($gameId, $p4);
        self::assertTrue($result['round_scored']);
        self::assertFalse($result['game_completed']);
        self::assertTrue($result['pending_decision'] ?? false);

        $scoredRound = $this->pdo->prepare('SELECT * FROM game_rounds WHERE id = :id');
        $scoredRound->execute(['id' => $round['id']]);
        $scoredRoundRow = $scoredRound->fetch();
        self::assertSame(0, (int) $scoredRoundRow['winner_team_id']);
        self::assertSame($p1, (int) $scoredRoundRow['winner_game_player_id']); // p1 scored higher than teammate p2

        // The losing team (team 1) gets a shared draw_recipient decision.
        $decision = $this->fetchOpenTeamDecision($gameId);
        self::assertSame(1, (int) $decision['team_id']);
        self::assertSame('draw_recipient', $decision['decision_type']);

        $handCountStmt = fn (int $gamePlayerId) => (int) $this->pdo
            ->query("SELECT COUNT(*) FROM game_cards WHERE owner_game_player_id = {$gamePlayerId} AND zone = 'hand'")
            ->fetchColumn();
        self::assertSame(0, $handCountStmt($p3), 'p3 already played their only hand card');
        self::assertSame(1, $handCountStmt($p4), 'p4 still has their unplayed Boredom');

        // p4 proposes p3 gets the draw; p3 (not the proposer) confirms.
        $this->games->proposeTeamDecision($gameId, $p4, $p3);
        $result = $this->games->confirmTeamDecision($gameId, $p3, true);
        self::assertFalse($result['round_scored']);
        self::assertFalse($result['game_completed']);

        self::assertSame(1, $handCountStmt($p3), 'p3 received the shared draw');
        self::assertSame(1, $handCountStmt($p4), 'p4 did not also draw');

        // A fresh round 2 exists, with its own turn_order decision opened
        // for team 0 -- the team that just won.
        $round2 = $this->fetchRound($gameId);
        self::assertSame(2, (int) $round2['round_number']);
        self::assertNull($round2['current_turn_game_player_id']);
        self::assertNull($round2['team_turn_1_game_player_id']);

        $decision = $this->fetchOpenTeamDecision($gameId);
        self::assertSame(0, (int) $decision['team_id']);
        self::assertSame('turn_order', $decision['decision_type']);
        self::assertEqualsCanonicalizing([$p1, $p2], array_map(intval(...), json_decode((string) $decision['candidate_game_player_ids'], true)));
    }

    /**
     * Regression test: team 2's own turn_order decision opens the moment
     * team 1's resolves (see applyTurnOrderDecision()'s own docblock), so
     * team 2 is free to answer it before team 1's chosen player has
     * actually taken turn 1. Resolving early must NOT hand the turn to
     * team 2's choice prematurely -- team 1's player still has to actually
     * play turn 1 first. (This previously clobbered
     * current_turn_game_player_id to team 2's choice immediately, silently
     * skipping team 1's own turn 1 entirely.)
     */
    public function testTeam2AnsweringTurnOrderEarlyDoesNotSkipTeam1sActualTurn(): void
    {
        [
            'gameId' => $gameId,
            'p1' => $p1,
            'p2' => $p2,
            'p3' => $p3,
            'p4' => $p4,
            'complacencyId' => $complacencyId,
        ] = $this->buildTeamFixture();

        // Team 1 decides p1 goes first.
        $this->games->proposeTeamDecision($gameId, $p1, $p1);
        $this->games->confirmTeamDecision($gameId, $p2, true);

        $round = $this->fetchRound($gameId);
        self::assertSame($p1, (int) $round['team_turn_1_game_player_id']);
        self::assertSame($p1, (int) $round['current_turn_game_player_id'], 'p1 must actually get turn 1');

        // Team 2 answers their own turn_order decision right away, before
        // p1 has played -- this must only record team_turn_2, not touch
        // whose turn it currently is.
        $this->games->proposeTeamDecision($gameId, $p3, $p3);
        $this->games->confirmTeamDecision($gameId, $p4, true);

        $round = $this->fetchRound($gameId);
        self::assertSame($p3, (int) $round['team_turn_2_game_player_id']);
        self::assertSame($p1, (int) $round['current_turn_game_player_id'], "p1's turn must not be clobbered by team 2 deciding early");
        self::assertFalse($this->fetchOpenTeamDecision($gameId), 'both decisions are already resolved');

        // p1 now actually plays turn 1 -- should go straight to p3 (team
        // 2's already-known choice) rather than freezing on a decision
        // that no longer exists to unfreeze it.
        $this->games->playMood($gameId, $p1, $complacencyId, []);
        $round = $this->fetchRound($gameId);
        self::assertSame($p3, (int) $round['current_turn_game_player_id']);
    }

    /**
     * Regression test: Chivalry/Triumph care about whoever PERSONALLY
     * took turn 1 this round, not which TEAM went first. game_rounds.
     * first_game_player_id, for a team game, only identifies a
     * representative member of the first team (here, p1) -- it can be a
     * completely different player than team_turn_1_game_player_id (the
     * team's own live choice of who actually goes, here p2). Chivalry
     * previously compared its owner against first_game_player_id
     * directly, so if the owner happened to BE that representative (p1)
     * but their TEAMMATE (p2) was the one who actually took turn 1, it
     * incorrectly read as "the owner went first" -- exactly the bug
     * reported live: a Chivalry owned by p1 scored 3 (base) instead of 5
     * (alt) even though p1 personally did not go first.
     */
    public function testChivalryAndTriumphCareWhoPersonallyWentFirstNotWhichTeamDid(): void
    {
        $u1 = $this->insertUser('chiv_p1');
        $u2 = $this->insertUser('chiv_p2');
        $u3 = $this->insertUser('chiv_p3');
        $u4 = $this->insertUser('chiv_p4');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('team', 'in_progress', :created_by, 2)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertTeamGamePlayer($gameId, $u1, 0, 0);
        $p2 = $this->insertTeamGamePlayer($gameId, $u2, 1, 0);
        $this->insertTeamGamePlayer($gameId, $u3, 2, 1);
        $this->insertTeamGamePlayer($gameId, $u4, 3, 1);

        $chivalryId = $this->insertGameCard($gameId, 4, 'hand', $p1); // white, base 3, alt 5
        $triumphId = $this->insertGameCard($gameId, 104, 'hand', $p2); // red, base 3, alt 5 -- the mirror image

        $roundId = $this->insertTeamGameRound($gameId, 1, $p1); // p1 is team 0's representative...
        // ...but p2 (p1's own teammate) was the one team 0 actually chose
        // to take turn 1, and has already done so -- it's now p1's own
        // forced turn 3.
        $this->pdo->prepare(
            'UPDATE game_rounds SET team_turn_1_game_player_id = :turn1, current_turn_game_player_id = :current, plays_remaining = 1 WHERE id = :id'
        )->execute(['turn1' => $p2, 'current' => $p1, 'id' => $roundId]);

        $this->games->playMood($gameId, $p1, $chivalryId, []);

        $state = $this->games->getState($gameId, $u1);
        $chivalryCard = array_values(array_filter($state['in_play'], fn (array $c) => $c['card_id'] === $chivalryId))[0];
        self::assertSame(5, $chivalryCard['value'], 'p1 did NOT personally go first (p2 did) -- Chivalry must read 5, not 3');
        self::assertSame($p2, $state['round']['went_first_game_player_id'], 'the Players list "went first" badge must key off p2 (who actually went), not p1 (team 0\'s representative)');

        // Play Triumph too (p2's own card) -- p2 DID personally go first,
        // so Triumph (the mirror image) must read its OWN alt value 5.
        $this->pdo->prepare('UPDATE game_rounds SET current_turn_game_player_id = :current WHERE id = :id')
            ->execute(['current' => $p2, 'id' => $roundId]);
        $this->games->playMood($gameId, $p2, $triumphId, []);

        $state = $this->games->getState($gameId, $u1);
        $triumphCard = array_values(array_filter($state['in_play'], fn (array $c) => $c['card_id'] === $triumphId))[0];
        self::assertSame(5, $triumphCard['value'], 'p2 DID personally go first -- Triumph must read 5');
    }

    /**
     * Stubbornness's own card text says "if ANOTHER PLAYER has more
     * moods than you," not "opponent" -- Open Team Play doesn't restrict
     * it (see php-app/README.md's "Open Team Play" section), so a
     * teammate having more moods must still grant the bonus play, exactly
     * as it always did before team format existed.
     */
    public function testStubbornnessGrantsABonusWhenATeammateHasMoreMoods(): void
    {
        [
            'gameId' => $gameId,
            'p1' => $p1,
            'p2' => $p2,
            'p3' => $p3,
            'p4' => $p4,
            'apathyId' => $apathyId,
            'indifferenceId' => $indifferenceId,
        ] = $this->buildTeamFixture();

        // p1 already has Stubbornness in play (so its bonus can apply on
        // a LATER turn of theirs -- it never applies on the turn it's
        // played, see StubbornnessEffect's own docblock), and teammate p2
        // already has 2 moods in play -- more than p1's own 1.
        $this->insertGameCard($gameId, 102, 'in_play', $p1); // Stubbornness, value 3
        $this->insertGameCard($gameId, 66, 'in_play', $p2); // Hate, value 0
        $this->insertGameCard($gameId, 105, 'in_play', $p2); // Wrath, value 0 -- p2 now has 2 moods

        // Team 0 decides p2 (not p1) goes first, so p1 gets the forced
        // turn 3 later.
        $this->games->proposeTeamDecision($gameId, $p1, $p2);
        $this->games->confirmTeamDecision($gameId, $p2, true);
        $this->games->playMood($gameId, $p2, $indifferenceId, []); // turn 1

        // Team 1 decides p3 goes first.
        $this->games->proposeTeamDecision($gameId, $p3, $p3);
        $this->games->confirmTeamDecision($gameId, $p4, true);
        $this->games->playMood($gameId, $p3, $apathyId, []); // turn 2 -- forced turn 3 (p1) follows

        $round = $this->fetchRound($gameId);
        self::assertSame($p1, (int) $round['current_turn_game_player_id']);
        self::assertSame(2, (int) $round['plays_remaining']); // base 1 + Stubbornness's bonus (teammate p2 has 2 moods > p1's 1)
    }

    public function testGetStateExposesTeamInfoTeammateHandAndTeamDecision(): void
    {
        [
            'gameId' => $gameId,
            'p1' => $p1,
            'p2' => $p2,
            'p3' => $p3,
            'indifferenceId' => $indifferenceId,
        ] = $this->buildTeamFixture();

        $p1UserId = (int) $this->pdo->query("SELECT user_id FROM game_players WHERE id = {$p1}")->fetchColumn();
        $p3UserId = (int) $this->pdo->query("SELECT user_id FROM game_players WHERE id = {$p3}")->fetchColumn();

        $stateForP1 = $this->games->getState($gameId, $p1UserId);

        foreach ($stateForP1['players'] as $player) {
            self::assertIsInt($player['team_id']);
        }

        self::assertCount(2, $stateForP1['teams']);
        $teamsById = array_column($stateForP1['teams'], null, 'team_id');
        self::assertEqualsCanonicalizing([$p1, $p2], $teamsById[0]['game_player_ids']);

        self::assertSame($p2, $stateForP1['you']['teammate_game_player_id']);
        $teammateHandCardIds = array_column($stateForP1['you']['teammate_hand'], 'card_id');
        self::assertContains($indifferenceId, $teammateHandCardIds);

        // p1 is on the deciding team (team 0) and the decision is still in
        // 'propose' phase, so p1 can propose.
        self::assertSame('turn_order', $stateForP1['team_decision']['decision_type']);
        self::assertTrue($stateForP1['team_decision']['can_propose']);
        self::assertFalse($stateForP1['team_decision']['can_confirm']);

        // p3 is on the OTHER team -- can see the decision exists, but can't act on it.
        $stateForP3 = $this->games->getState($gameId, $p3UserId);
        self::assertFalse($stateForP3['team_decision']['can_propose']);
        self::assertFalse($stateForP3['team_decision']['can_confirm']);
    }

    /**
     * Regression test: a team-format game's completion must credit BOTH
     * teammates on the winning team, not just whichever one happened to
     * score higher that round (game['winner_game_player_id'] is still
     * that single representative, for internal FK purposes -- see
     * finishTeamScoringAndAdvance()'s own docblock -- but
     * winner_usernames is what the frontend actually displays).
     */
    public function testGetStateCreditsBothTeammatesAsWinnersWhenTheGameCompletes(): void
    {
        $u1 = $this->insertUser('winteam-p1');
        $u2 = $this->insertUser('winteam-p2');
        $u3 = $this->insertUser('winteam-p3');
        $u4 = $this->insertUser('winteam-p4');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('team', 'in_progress', :created_by, 1)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertTeamGamePlayer($gameId, $u1, 0, 0);
        $p2 = $this->insertTeamGamePlayer($gameId, $u2, 1, 0);
        $p3 = $this->insertTeamGamePlayer($gameId, $u3, 2, 1);
        $p4 = $this->insertTeamGamePlayer($gameId, $u4, 3, 1);

        $complacencyId = $this->insertGameCard($gameId, 5, 'hand', $p1); // white, value 4
        $this->insertGameCard($gameId, 44, 'hand', $p2); // blue, value 4 -- p2 passes
        $this->insertGameCard($gameId, 55, 'hand', $p3); // black, value 4 -- p3 passes
        $this->insertGameCard($gameId, 83, 'hand', $p4); // red, value 4 -- p4 passes

        // Skip the propose/confirm flow (covered elsewhere) -- go straight
        // to turn 1 (p1) with turn 2 (p3) already decided, so the round
        // runs p1 -> p3 -> p2 (team 0's forced remaining member) -> p4
        // (team 1's forced remaining member) per advanceTeamTurn().
        $roundId = $this->insertTeamGameRound($gameId, 1, $p1);
        $this->pdo->prepare(
            'UPDATE game_rounds SET current_turn_game_player_id = :p1, plays_remaining = 1, team_turn_1_game_player_id = :p1, team_turn_2_game_player_id = :p3 WHERE id = :round_id'
        )->execute(['p1' => $p1, 'p3' => $p3, 'round_id' => $roundId]);

        // Team 0 (p1 + p2) scores 4 to team 1's 0 -- an outright win that,
        // with wins_needed = 1, also ends the game.
        $this->games->playMood($gameId, $p1, $complacencyId, []);
        $this->games->pass($gameId, $p3);
        $this->games->pass($gameId, $p2);
        $result = $this->games->pass($gameId, $p4);

        self::assertTrue($result['round_scored']);
        self::assertTrue($result['game_completed']);

        $game = $this->fetchGame($gameId);
        self::assertSame(0, (int) $game['winner_team_id']);

        // Every viewer -- including the losing team -- must see BOTH
        // teammates on the winning team credited, not just whichever one
        // scored higher.
        foreach ([$u1, $u2, $u3, $u4] as $viewerUserId) {
            $state = $this->games->getState($gameId, $viewerUserId);
            self::assertEqualsCanonicalizing(['winteam-p1', 'winteam-p2'], $state['game']['winner_usernames']);
        }
    }

    // --- Closed Team Play (issue #87) ---

    public function testCreateGameClosedTeamFormatRequiresExactlyFourPlayers(): void
    {
        $u1 = $this->insertUser('cct1');
        $u2 = $this->insertUser('cct2');
        $u3 = $this->insertUser('cct3');

        $this->expectException(GameStateException::class);
        $this->expectExceptionMessage('exactly 4 players');
        $this->games->createGame($u1, [$u1, $u2, $u3], 'closed_team', 3, 'structure', null, null, $u2);
    }

    public function testCreateGameClosedTeamFormatRequiresPartnerUserId(): void
    {
        $u1 = $this->insertUser('cct1');
        $u2 = $this->insertUser('cct2');
        $u3 = $this->insertUser('cct3');
        $u4 = $this->insertUser('cct4');

        $this->expectException(GameStateException::class);
        $this->expectExceptionMessage('partner');
        $this->games->createGame($u1, [$u1, $u2, $u3, $u4], 'closed_team', 3, 'structure', null, null, null);
    }

    public function testCreateGameClosedTeamFormatRejectsPowerDeckType(): void
    {
        $u1 = $this->insertUser('cct1');
        $u2 = $this->insertUser('cct2');
        $u3 = $this->insertUser('cct3');
        $u4 = $this->insertUser('cct4');

        $this->expectException(GameStateException::class);
        $this->expectExceptionMessage('45-card minimum');
        $this->games->createGame($u1, [$u1, $u2, $u3, $u4], 'closed_team', 3, 'power', null, null, $u2);
    }

    public function testCreateGameClosedTeamFormatSeatsPartnersAcrossAndAssignsTeamIds(): void
    {
        $u1 = $this->insertUser('cct1');
        $u2 = $this->insertUser('cct2');
        $u3 = $this->insertUser('cct3');
        $u4 = $this->insertUser('cct4');

        $gameId = $this->games->createGame($u1, [$u1, $u2, $u3, $u4], 'closed_team', 3, 'structure', null, null, $u3);

        $stmt = $this->pdo->prepare(
            'SELECT user_id, seat_order, team_id FROM game_players WHERE game_id = :game_id ORDER BY seat_order ASC'
        );
        $stmt->execute(['game_id' => $gameId]);
        $rows = $stmt->fetchAll();

        self::assertSame($u1, (int) $rows[0]['user_id']);
        self::assertSame(0, (int) $rows[0]['team_id']);
        self::assertSame($u2, (int) $rows[1]['user_id']);
        self::assertSame(1, (int) $rows[1]['team_id']);
        // The creator's chosen partner (u3) sits ACROSS the table -- seat
        // 2, not adjacent like Open Team Play's seat 1.
        self::assertSame($u3, (int) $rows[2]['user_id']);
        self::assertSame(0, (int) $rows[2]['team_id']);
        self::assertSame($u4, (int) $rows[3]['user_id']);
        self::assertSame(1, (int) $rows[3]['team_id']);
    }

    /**
     * Team 0 = p1/p3 (seats 0/2), Team 1 = p2/p4 (seats 1/3) -- across-the-
     * table seating per "Closed Team Play" in php-app/README.md, unlike
     * Open Team Play's adjacent pairing. Each player gets 2 vanilla
     * no-ability value-4 hand cards -- enough to exercise the initial
     * card pass and one play afterward -- and round 1 starts already
     * frozen (current_turn_game_player_id NULL, first_game_player_id
     * already p1, the real randomly-chosen leader) exactly the way
     * startGame() would leave it; unlike Open Team Play, no
     * game_team_decisions row exists yet for round 1.
     *
     * @return array{gameId:int, roundId:int, p1:int, p2:int, p3:int, p4:int}
     */
    private function buildClosedTeamFixture(): array
    {
        $u1 = $this->insertUser('closedteam1');
        $u2 = $this->insertUser('closedteam2');
        $u3 = $this->insertUser('closedteam3');
        $u4 = $this->insertUser('closedteam4');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('closed_team', 'in_progress', :created_by, 2)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertTeamGamePlayer($gameId, $u1, 0, 0);
        $p2 = $this->insertTeamGamePlayer($gameId, $u2, 1, 1);
        $p3 = $this->insertTeamGamePlayer($gameId, $u3, 2, 0);
        $p4 = $this->insertTeamGamePlayer($gameId, $u4, 3, 1);

        $this->insertGameCard($gameId, 5, 'hand', $p1); // white, value 4
        $this->insertGameCard($gameId, 44, 'hand', $p1); // blue, value 4
        $this->insertGameCard($gameId, 55, 'hand', $p2); // black, value 4
        $this->insertGameCard($gameId, 83, 'hand', $p2); // red, value 4
        $this->insertGameCard($gameId, 126, 'hand', $p3); // green, value 4
        $this->insertGameCard($gameId, 5, 'hand', $p3); // white, value 4
        $this->insertGameCard($gameId, 44, 'hand', $p4); // blue, value 4
        $this->insertGameCard($gameId, 55, 'hand', $p4); // black, value 4
        $this->insertGameCard($gameId, 27, 'deck', null, 0);
        $this->insertGameCard($gameId, 54, 'deck', null, 1);

        $roundId = $this->insertTeamGameRound($gameId, 1, $p1);

        return ['gameId' => $gameId, 'roundId' => $roundId, 'p1' => $p1, 'p2' => $p2, 'p3' => $p3, 'p4' => $p4];
    }

    public function testSubmitInitialCardPassRequiresExactlyTwoCards(): void
    {
        ['gameId' => $gameId, 'p1' => $p1] = $this->buildClosedTeamFixture();
        $hand = array_map(intval(...), $this->pdo
            ->query("SELECT id FROM game_cards WHERE owner_game_player_id = {$p1} AND zone = 'hand'")
            ->fetchAll(PDO::FETCH_COLUMN));

        $this->expectException(GameStateException::class);
        $this->expectExceptionMessage('exactly 2 cards');
        $this->games->submitInitialCardPass($gameId, $p1, [$hand[0]]);
    }

    public function testSubmitInitialCardPassRejectsCardsNotInHand(): void
    {
        ['gameId' => $gameId, 'p1' => $p1, 'p2' => $p2] = $this->buildClosedTeamFixture();
        $otherHand = array_map(intval(...), $this->pdo
            ->query("SELECT id FROM game_cards WHERE owner_game_player_id = {$p2} AND zone = 'hand'")
            ->fetchAll(PDO::FETCH_COLUMN));

        $this->expectException(GameStateException::class);
        $this->expectExceptionMessage("isn't in your hand");
        $this->games->submitInitialCardPass($gameId, $p1, $otherHand);
    }

    public function testSubmitInitialCardPassRejectsDuplicateSubmission(): void
    {
        ['gameId' => $gameId, 'p1' => $p1] = $this->buildClosedTeamFixture();
        $hand = array_map(intval(...), $this->pdo
            ->query("SELECT id FROM game_cards WHERE owner_game_player_id = {$p1} AND zone = 'hand'")
            ->fetchAll(PDO::FETCH_COLUMN));

        $this->games->submitInitialCardPass($gameId, $p1, $hand);

        $this->expectException(GameStateException::class);
        $this->expectExceptionMessage('already passed');
        $this->games->submitInitialCardPass($gameId, $p1, $hand);
    }

    /**
     * Team 0's own swap (p1/p3) must resolve the moment BOTH of them have
     * submitted, independent of team 1's own pace -- and only once ALL 4
     * players (both teams) have submitted does round 1 actually unfreeze,
     * to the leader startGame() already randomly chose (p1), per "Closed
     * Team Play" in php-app/README.md.
     */
    public function testSubmitInitialCardPassAppliesTeamSwapIndependentlyThenUnfreezesRoundOnceAllFourSubmit(): void
    {
        [
            'gameId' => $gameId,
            'p1' => $p1,
            'p2' => $p2,
            'p3' => $p3,
            'p4' => $p4,
        ] = $this->buildClosedTeamFixture();

        $handOf = fn (int $gamePlayerId): array => array_map(intval(...), $this->pdo
            ->query("SELECT id FROM game_cards WHERE owner_game_player_id = {$gamePlayerId} AND zone = 'hand' ORDER BY id")
            ->fetchAll(PDO::FETCH_COLUMN));

        $p1Hand = $handOf($p1);
        $p3Hand = $handOf($p3);

        $result = $this->games->submitInitialCardPass($gameId, $p1, $p1Hand);
        self::assertTrue($result['pending_decision']);
        self::assertSame($p1Hand, $handOf($p1), "p1's own cards mustn't move until p3 (their teammate) has also passed");

        $result = $this->games->submitInitialCardPass($gameId, $p3, $p3Hand);
        self::assertTrue($result['pending_decision'], 'team 1 (p2/p4) has not passed yet');

        // Team 0's own swap has already resolved, independent of team 1.
        self::assertSame($p3Hand, $handOf($p1), "p1 should now hold p3's original 2 cards");
        self::assertSame($p1Hand, $handOf($p3), "p3 should now hold p1's original 2 cards");

        $round = $this->fetchRound($gameId);
        self::assertNull($round['current_turn_game_player_id'], 'round 1 must stay frozen until team 1 also passes');

        $this->games->submitInitialCardPass($gameId, $p2, $handOf($p2));
        $result = $this->games->submitInitialCardPass($gameId, $p4, $handOf($p4));
        self::assertFalse($result['pending_decision']);

        $round = $this->fetchRound($gameId);
        self::assertSame($p1, (int) $round['current_turn_game_player_id'], 'round 1 unfreezes to the already-chosen leader once all 4 have passed');
        self::assertSame(1, (int) $round['plays_remaining']);
    }

    public function testGetStateExposesInitialCardPassStatusAndHidesTeammateHandForClosedTeam(): void
    {
        [
            'gameId' => $gameId,
            'p1' => $p1,
            'p3' => $p3,
        ] = $this->buildClosedTeamFixture();
        $p1UserId = (int) $this->pdo->query("SELECT user_id FROM game_players WHERE id = {$p1}")->fetchColumn();

        $stateBefore = $this->games->getState($gameId, $p1UserId);
        self::assertSame($p3, $stateBefore['you']['teammate_game_player_id'], 'the teammate is still identified, unlike their hand');
        self::assertArrayNotHasKey('teammate_hand', $stateBefore['you'], "closed_team must never expose a teammate's hand");
        self::assertNotNull($stateBefore['initial_card_pass']);
        self::assertFalse($stateBefore['initial_card_pass']['you_submitted']);
        self::assertSame([], $stateBefore['initial_card_pass']['submitted_game_player_ids']);

        $p1Hand = array_map(intval(...), $this->pdo
            ->query("SELECT id FROM game_cards WHERE owner_game_player_id = {$p1} AND zone = 'hand'")
            ->fetchAll(PDO::FETCH_COLUMN));
        $this->games->submitInitialCardPass($gameId, $p1, $p1Hand);

        $stateAfter = $this->games->getState($gameId, $p1UserId);
        self::assertTrue($stateAfter['initial_card_pass']['you_submitted']);
        self::assertSame([$p1], $stateAfter['initial_card_pass']['submitted_game_player_ids']);
    }

    /**
     * End-to-end: the initial blind card pass, a full round (already-
     * randomized leader p1, plain clockwise turn order alternating teams
     * thanks to across-the-table seating), team-aggregated scoring, the
     * losing team's shared draw, and round 2's own single leader decision
     * -- resolved to p1's teammate p3 -- writing straight into
     * first_game_player_id and unfreezing immediately with no second
     * decision, unlike Open Team Play.
     */
    public function testClosedTeamFullRoundCycleWithInitialPassLeaderDecisionAndSharedDraw(): void
    {
        [
            'gameId' => $gameId,
            'p1' => $p1,
            'p2' => $p2,
            'p3' => $p3,
            'p4' => $p4,
        ] = $this->buildClosedTeamFixture();

        $handOf = fn (int $gamePlayerId): array => array_map(intval(...), $this->pdo
            ->query("SELECT id FROM game_cards WHERE owner_game_player_id = {$gamePlayerId} AND zone = 'hand' ORDER BY id")
            ->fetchAll(PDO::FETCH_COLUMN));

        foreach ([$p1, $p2, $p3, $p4] as $gamePlayerId) {
            $this->games->submitInitialCardPass($gameId, $gamePlayerId, $handOf($gamePlayerId));
        }

        $round = $this->fetchRound($gameId);
        self::assertSame($p1, (int) $round['current_turn_game_player_id']);

        // Turn 1: p1 (team 0) plays one of their post-swap cards (value 4).
        $this->games->playMood($gameId, $p1, $handOf($p1)[0], []);
        $round = $this->fetchRound($gameId);
        self::assertSame($p2, (int) $round['current_turn_game_player_id'], 'plain clockwise rotation -- across seating already alternates teams');

        // Turn 2: p2 (team 1) passes.
        $this->games->pass($gameId, $p2);
        $round = $this->fetchRound($gameId);
        self::assertSame($p3, (int) $round['current_turn_game_player_id']);

        // Turn 3: p3 (team 0, p1's teammate) plays their own post-swap card (value 4).
        $this->games->playMood($gameId, $p3, $handOf($p3)[0], []);
        $round = $this->fetchRound($gameId);
        self::assertSame($p4, (int) $round['current_turn_game_player_id']);

        // Turn 4: p4 (team 1) passes -- round scores. Team 0: 4 + 4 = 8, team 1: 0.
        $result = $this->games->pass($gameId, $p4);
        self::assertTrue($result['round_scored']);
        self::assertFalse($result['game_completed']);
        self::assertTrue($result['pending_decision'] ?? false);

        $scoredRound = $this->pdo->prepare('SELECT * FROM game_rounds WHERE id = :id');
        $scoredRound->execute(['id' => $round['id']]);
        self::assertSame(0, (int) $scoredRound->fetch()['winner_team_id']);

        // The losing team (team 1: p2/p4) gets a shared draw_recipient decision.
        $decision = $this->fetchOpenTeamDecision($gameId);
        self::assertSame(1, (int) $decision['team_id']);
        self::assertSame('draw_recipient', $decision['decision_type']);

        $this->games->proposeTeamDecision($gameId, $p2, $p4);
        $result = $this->games->confirmTeamDecision($gameId, $p4, true);
        self::assertFalse($result['round_scored']);
        self::assertFalse($result['game_completed']);

        // Round 2 opens frozen with team 0's (the winner's) own single leader decision.
        $decision = $this->fetchOpenTeamDecision($gameId);
        self::assertSame(0, (int) $decision['team_id']);
        self::assertSame('turn_order', $decision['decision_type']);
        self::assertEqualsCanonicalizing([$p1, $p3], array_map(intval(...), json_decode((string) $decision['candidate_game_player_ids'], true)));

        $round2 = $this->fetchRound($gameId);
        self::assertSame(2, (int) $round2['round_number']);
        self::assertNull($round2['current_turn_game_player_id']);

        // Team 0 chooses p3 (not p1) to lead round 2.
        $this->games->proposeTeamDecision($gameId, $p3, $p3);
        $this->games->confirmTeamDecision($gameId, $p1, true);

        $round2 = $this->fetchRound($gameId);
        self::assertSame($p3, (int) $round2['first_game_player_id'], 'closed_team writes the chosen leader straight into first_game_player_id -- no team_turn_1/2 columns');
        self::assertSame($p3, (int) $round2['current_turn_game_player_id'], 'unfreezes immediately -- only one decision exists per round for this format');
        self::assertFalse($this->fetchOpenTeamDecision($gameId), 'no second decision opens, unlike Open Team Play');
    }

    // -- Quick Draft (issue #88) --------------------------------------------

    /** @return array{gameId:int, u1:int, u2:int} */
    private function buildQuickDraftFixture(
        string $poolSource = 'random_48',
        ?string $customPoolText = null,
        int $winsNeeded = 1,
    ): array {
        $u1 = $this->insertUser('quickdraft-' . uniqid('u1'));
        $u2 = $this->insertUser('quickdraft-' . uniqid('u2'));

        $gameId = $this->games->createGame(
            $u1,
            [$u1, $u2],
            format: 'draft',
            winsNeeded: $winsNeeded,
            deckType: 'quick_draft',
            quickDraftPoolSource: $poolSource,
            quickDraftCustomPoolText: $customPoolText,
        );

        return ['gameId' => $gameId, 'u1' => $u1, 'u2' => $u2];
    }

    private function fetchDraftMatch(int $draftMatchId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM draft_matches WHERE id = :id');
        $stmt->execute(['id' => $draftMatchId]);

        return $stmt->fetch();
    }

    private function fetchDraftMatchPlayer(int $draftMatchId, int $userId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM draft_match_players WHERE draft_match_id = :match_id AND user_id = :user_id');
        $stmt->execute(['match_id' => $draftMatchId, 'user_id' => $userId]);

        return $stmt->fetch();
    }

    /**
     * Drives a full 4-round Quick Draft to completion for both $u1/$u2,
     * always keeping the pack's first QUICK_DRAFT_KEEP_PER_STAGE cards at
     * each stage -- exercised purely through the public API
     * (submitQuickDraftPick()/getState()), the same surface the real
     * frontend uses. The getState()-reported pack size is asserted at
     * every single stage of every round (6 for 'draw', 4 for 'received') --
     * this alone proves both the multiset-subtraction math (a wrong
     * array_diff()-based passed/received computation would desync these
     * counts the moment a duplicate catalog id was involved) and the
     * round-4 discard-reshuffle top-up path (a pool smaller than 48 would
     * short round 4's draw pack below 6 without it).
     */
    private function driveQuickDraftToDeckBuilding(int $gameId, int $u1, int $u2): void
    {
        for ($round = 1; $round <= 4; $round++) {
            foreach ([$u1, $u2] as $userId) {
                $state = $this->games->getState($gameId, $userId);
                self::assertSame('drafting', $state['quick_draft']['status']);
                self::assertSame($round, $state['quick_draft']['drafting']['round']);
                self::assertSame('draw', $state['quick_draft']['drafting']['stage']);
                $pack = $state['quick_draft']['drafting']['pack'];
                self::assertCount(6, $pack, "round {$round} draw pack for user {$userId}");

                $this->games->submitQuickDraftPick($gameId, $userId, $round, 'draw', [$pack[0]['card_id'], $pack[1]['card_id']]);
            }

            foreach ([$u1, $u2] as $userId) {
                $state = $this->games->getState($gameId, $userId);
                self::assertSame('received', $state['quick_draft']['drafting']['stage']);
                $pack = $state['quick_draft']['drafting']['pack'];
                self::assertCount(4, $pack, "round {$round} received pack for user {$userId}");

                $this->games->submitQuickDraftPick($gameId, $userId, $round, 'received', [$pack[0]['card_id'], $pack[1]['card_id']]);
            }
        }
    }

    /** Submits all 16 of $userId's own drafted cards as their deck (the max end of the 12-16 range). */
    private function submitFullQuickDraftDeck(int $gameId, int $userId): void
    {
        $state = $this->games->getState($gameId, $userId);
        $cardIds = array_column($state['quick_draft']['deck_building']['drafted_cards'], 'card_id');
        self::assertCount(16, $cardIds);

        $this->games->submitDraftDeck($gameId, $userId, $cardIds);
    }

    /**
     * Both players pass immediately -- the round scores 0-0, and
     * RoundScorer::winner() breaks that tie in favor of whoever's turn was
     * first this round, so this deterministically hands the game to
     * $round['first_game_player_id'] regardless of which user that
     * happens to be for this particular game.
     *
     * @return int the winner's user_id
     */
    private function completeQuickDraftGameByPassing(int $gameId): int
    {
        $round = $this->fetchRound($gameId);
        $firstGamePlayerId = (int) $round['first_game_player_id'];

        $result1 = $this->games->pass($gameId, $firstGamePlayerId);
        self::assertFalse($result1['game_completed']);

        $round = $this->fetchRound($gameId);
        $secondGamePlayerId = (int) $round['current_turn_game_player_id'];
        $result2 = $this->games->pass($gameId, $secondGamePlayerId);
        self::assertTrue($result2['game_completed']);
        self::assertSame($firstGamePlayerId, $result2['winner_game_player_id']);

        $userIdStmt = $this->pdo->prepare('SELECT user_id FROM game_players WHERE id = :id');
        $userIdStmt->execute(['id' => $firstGamePlayerId]);

        return (int) $userIdStmt->fetchColumn();
    }

    public function testCreateGameRejectsQuickDraftForNonDraftFormat(): void
    {
        $creator = $this->insertUser('quickdraft-nondraft-alice');
        $bob = $this->insertUser('quickdraft-nondraft-bob');

        $this->expectException(GameStateException::class);
        $this->expectExceptionMessage('only supported for the "draft" format');

        $this->games->createGame($creator, [$creator, $bob], deckType: 'quick_draft', quickDraftPoolSource: 'random_48');
    }

    public function testCreateGameRejectsDraftFormatForNonQuickDraftDeckType(): void
    {
        $creator = $this->insertUser('draft-nonquickdraft-alice');
        $bob = $this->insertUser('draft-nonquickdraft-bob');

        $this->expectException(GameStateException::class);
        $this->expectExceptionMessage('only supports the "quick_draft"/"winston_draft"/"grid_draft" deck types');

        $this->games->createGame($creator, [$creator, $bob], format: 'draft', deckType: 'structure');
    }

    public function testCreateGameRejectsADraftGameWithMoreThanTwoPlayers(): void
    {
        $u1 = $this->insertUser('drafttoomany1');
        $u2 = $this->insertUser('drafttoomany2');
        $u3 = $this->insertUser('drafttoomany3');

        $this->expectException(GameStateException::class);
        $this->games->createGame($u1, [$u1, $u2, $u3], format: 'draft', deckType: 'quick_draft', quickDraftPoolSource: 'random_48');
    }

    public function testCreateGameRejectsADraftGameWithFewerThanTwoPlayers(): void
    {
        $u1 = $this->insertUser('drafttoofew1');

        $this->expectException(GameStateException::class);
        $this->games->createGame($u1, [$u1], format: 'draft', deckType: 'quick_draft', quickDraftPoolSource: 'random_48');
    }

    public function testStartGameGivesEachDraftPlayerTheirOwnIndependentDeck(): void
    {
        ['gameId' => $gameId, 'u1' => $u1, 'u2' => $u2] = $this->buildQuickDraftFixture('random_48');
        $this->driveQuickDraftToDeckBuilding($gameId, $u1, $u2);
        $this->submitFullQuickDraftDeck($gameId, $u1);
        $this->submitFullQuickDraftDeck($gameId, $u2);
        $this->games->startGame($gameId);

        $p1 = $this->games->gamePlayerIdFor($gameId, $u1);
        $p2 = $this->games->gamePlayerIdFor($gameId, $u2);

        $nullOwnerStmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM game_cards WHERE game_id = :game_id AND zone = 'deck' AND owner_game_player_id IS NULL"
        );
        $nullOwnerStmt->execute(['game_id' => $gameId]);
        self::assertSame(0, (int) $nullOwnerStmt->fetchColumn(), 'a draft-format game must not deal into a shared, ownerless pool');

        foreach ([$p1, $p2] as $playerId) {
            $deckCountStmt = $this->pdo->prepare(
                "SELECT COUNT(*) FROM game_cards WHERE game_id = :game_id AND zone = 'deck' AND owner_game_player_id = :owner"
            );
            $deckCountStmt->execute(['game_id' => $gameId, 'owner' => $playerId]);
            self::assertGreaterThan(0, (int) $deckCountStmt->fetchColumn(), "player {$playerId} must have their own separate deck");
        }
    }

    public function testCreateGameQuickDraftRandom48PoolHasFortyEightDistinctCards(): void
    {
        ['gameId' => $gameId] = $this->buildQuickDraftFixture('random_48');

        $game = $this->fetchGame($gameId);
        $poolCardIds = json_decode($this->fetchDraftMatch((int) $game['draft_match_id'])['pool_card_ids'], true);

        self::assertCount(48, $poolCardIds);
        self::assertCount(48, array_unique($poolCardIds), 'random_48 is drawn without replacement -- always singleton');
    }

    public function testCreateGameQuickDraftStructurePoolHasFortyFiveCards(): void
    {
        ['gameId' => $gameId] = $this->buildQuickDraftFixture('structure');

        $game = $this->fetchGame($gameId);
        $poolCardIds = json_decode($this->fetchDraftMatch((int) $game['draft_match_id'])['pool_card_ids'], true);

        self::assertCount(45, $poolCardIds);
    }

    public function testCreateGameQuickDraftOneOfEachPoolIsTruncatedToFortyEight(): void
    {
        ['gameId' => $gameId] = $this->buildQuickDraftFixture('one_of_each');

        $game = $this->fetchGame($gameId);
        $poolCardIds = json_decode($this->fetchDraftMatch((int) $game['draft_match_id'])['pool_card_ids'], true);

        // "if more than 48 cards are in the pool, just ignore the extra
        // cards at the end of the draft" -- the repo owner's own words for
        // this feature -- 133 cards randomly truncated down to 48.
        self::assertCount(48, $poolCardIds);
    }

    public function testCreateGameQuickDraftJceddys75PoolIsTruncatedToFortyEight(): void
    {
        ['gameId' => $gameId] = $this->buildQuickDraftFixture('jceddys_75');

        $game = $this->fetchGame($gameId);
        $poolCardIds = json_decode($this->fetchDraftMatch((int) $game['draft_match_id'])['pool_card_ids'], true);

        // Same truncation rule as one_of_each's 133 -- jceddy's 75 Card
        // deck's own 75-card pool (see GameService::buildJceddys75DeckCardIds())
        // gets randomly narrowed down to 48 before drafting begins.
        self::assertCount(48, $poolCardIds);
    }

    public function testCreateGameQuickDraftCustomPoolRejectsFewerThanFortyFiveCards(): void
    {
        $u1 = $this->insertUser('quickdraft-toofewpool1');
        $u2 = $this->insertUser('quickdraft-toofewpool2');

        $this->expectException(GameStateException::class);
        $this->expectExceptionMessage('at least 45 are required');

        $this->games->createGame(
            $u1,
            [$u1, $u2],
            format: 'draft',
            deckType: 'quick_draft',
            quickDraftPoolSource: 'custom',
            quickDraftCustomPoolText: "1 Charity\n1 Chivalry",
        );
    }

    public function testFullDraftWithARandom48PoolProducesSixteenKeptCardsPerPlayer(): void
    {
        ['gameId' => $gameId, 'u1' => $u1, 'u2' => $u2] = $this->buildQuickDraftFixture('random_48');

        $this->driveQuickDraftToDeckBuilding($gameId, $u1, $u2);

        $game = $this->fetchGame($gameId);
        $draftMatchId = (int) $game['draft_match_id'];
        $match = $this->fetchDraftMatch($draftMatchId);
        self::assertSame('deck_building', $match['status']);

        foreach ([$u1, $u2] as $userId) {
            $player = $this->fetchDraftMatchPlayer($draftMatchId, $userId);
            $draftedCardIds = json_decode($player['drafted_card_ids'], true);
            self::assertCount(16, $draftedCardIds, "user {$userId} should have drafted exactly 16 cards");
        }
    }

    public function testFullDraftWithAFortySixCardCustomPoolContainingADuplicateForcesTheRoundFourTopUp(): void
    {
        // 45 distinct catalog ids (1-45) plus one extra copy of Altruism
        // (id 1) -- 46 total, exercising both the multiset-duplicate path
        // (Altruism can legally appear twice in the shared pool) and the
        // round-4 discard-reshuffle top-up (46 < 48, so by round 4 the
        // remaining pool alone would be short of the 12 cards that round
        // needs -- see dealQuickDraftRound()).
        $poolText = "1 Altruism\n1 Benevolence\n1 Charity\n1 Chivalry\n1 Complacency\n1 Conviction\n1 Courage\n1 Dignity\n"
            . "1 Discipline\n1 Disillusionment\n1 Encouragement\n1 Faith\n1 Friendliness\n1 Guilt\n1 Honor\n1 Idealism\n"
            . "1 Kindness\n1 Loyalty\n1 Meekness\n1 Pacifism\n1 Patience\n1 Pride\n1 Repentance\n1 Scorn\n1 Shame\n1 Validation\n"
            . "1 Ambivalence\n1 Anxiety\n1 Avoidance\n1 Bashfulness\n1 Confusion\n1 Creativity\n1 Curiosity\n1 Denial\n"
            . "1 Disorientation\n1 Doubt\n1 Duplicity\n1 Fear\n1 Fickleness\n1 Guile\n1 Hesitation\n1 Imagination\n"
            . "1 Indecisiveness\n1 Indifference\n1 Insecurity\n1 Altruism";

        ['gameId' => $gameId, 'u1' => $u1, 'u2' => $u2] = $this->buildQuickDraftFixture('custom', $poolText);

        $game = $this->fetchGame($gameId);
        $draftMatchId = (int) $game['draft_match_id'];
        self::assertCount(46, json_decode($this->fetchDraftMatch($draftMatchId)['pool_card_ids'], true));

        // driveQuickDraftToDeckBuilding() itself asserts a full 6-card draw
        // pack and 4-card received pack every round, including round 4 --
        // that's only possible if the shortfall (46 - 36 = 10 remaining,
        // 2 short of the 12 round 4 needs) was actually topped up from
        // already-discarded cards.
        $this->driveQuickDraftToDeckBuilding($gameId, $u1, $u2);

        foreach ([$u1, $u2] as $userId) {
            $draftedCardIds = json_decode($this->fetchDraftMatchPlayer($draftMatchId, $userId)['drafted_card_ids'], true);
            self::assertCount(16, $draftedCardIds);
        }
    }

    public function testSubmitQuickDraftPickRejectsTheWrongNumberOfCards(): void
    {
        ['gameId' => $gameId, 'u1' => $u1] = $this->buildQuickDraftFixture();
        $state = $this->games->getState($gameId, $u1);
        $pack = $state['quick_draft']['drafting']['pack'];

        $this->expectException(GameStateException::class);
        $this->expectExceptionMessage('exactly 2 cards');

        $this->games->submitQuickDraftPick($gameId, $u1, 1, 'draw', [$pack[0]['card_id']]);
    }

    public function testSubmitQuickDraftPickRejectsACardNotInYourDrawnPack(): void
    {
        ['gameId' => $gameId, 'u1' => $u1] = $this->buildQuickDraftFixture();
        $state = $this->games->getState($gameId, $u1);
        $pack = $state['quick_draft']['drafting']['pack'];
        $packCardIds = array_column($pack, 'card_id');

        $notInPack = null;
        for ($cardId = 1; $cardId <= 133; $cardId++) {
            if (!in_array($cardId, $packCardIds, true)) {
                $notInPack = $cardId;
                break;
            }
        }

        $this->expectException(GameStateException::class);
        $this->expectExceptionMessage('only keep cards you were actually dealt');

        $this->games->submitQuickDraftPick($gameId, $u1, 1, 'draw', [$pack[0]['card_id'], $notInPack]);
    }

    public function testSubmitQuickDraftPickRejectsResubmittingTheSameStage(): void
    {
        ['gameId' => $gameId, 'u1' => $u1] = $this->buildQuickDraftFixture();
        $state = $this->games->getState($gameId, $u1);
        $pack = $state['quick_draft']['drafting']['pack'];
        $keep = [$pack[0]['card_id'], $pack[1]['card_id']];

        $this->games->submitQuickDraftPick($gameId, $u1, 1, 'draw', $keep);

        $this->expectException(GameStateException::class);
        $this->expectExceptionMessage('already made your pick');

        $this->games->submitQuickDraftPick($gameId, $u1, 1, 'draw', $keep);
    }

    public function testSubmitQuickDraftPickRejectsReceivedStageBeforeDrawStage(): void
    {
        ['gameId' => $gameId, 'u1' => $u1] = $this->buildQuickDraftFixture();

        $this->expectException(GameStateException::class);
        $this->expectExceptionMessage('must submit your draw pick before your received-card pick');

        $this->games->submitQuickDraftPick($gameId, $u1, 1, 'received', [1, 2]);
    }

    public function testSubmitQuickDraftPickRejectsReceivedStageBeforeTheOpponentHasSubmittedDraw(): void
    {
        ['gameId' => $gameId, 'u1' => $u1] = $this->buildQuickDraftFixture();
        $state = $this->games->getState($gameId, $u1);
        $pack = $state['quick_draft']['drafting']['pack'];

        $this->games->submitQuickDraftPick($gameId, $u1, 1, 'draw', [$pack[0]['card_id'], $pack[1]['card_id']]);

        $this->expectException(GameStateException::class);
        $this->expectExceptionMessage("opponent hasn't made their draw pick yet");

        $this->games->submitQuickDraftPick($gameId, $u1, 1, 'received', [$pack[2]['card_id'], $pack[3]['card_id']]);
    }

    public function testStartGameRejectsAQuickDraftGameUntilBothPlayersHaveSubmittedADeck(): void
    {
        ['gameId' => $gameId, 'u1' => $u1, 'u2' => $u2] = $this->buildQuickDraftFixture();
        $this->driveQuickDraftToDeckBuilding($gameId, $u1, $u2);

        $this->submitFullQuickDraftDeck($gameId, $u1);

        $this->expectException(GameStateException::class);
        $this->expectExceptionMessage('cannot start until both players have submitted their drafted deck');

        $this->games->startGame($gameId);
    }

    public function testSubmitQuickDraftDeckRejectsAnOutOfRangeCardCount(): void
    {
        ['gameId' => $gameId, 'u1' => $u1, 'u2' => $u2] = $this->buildQuickDraftFixture();
        $this->driveQuickDraftToDeckBuilding($gameId, $u1, $u2);

        $draftedCardIds = array_column(
            $this->games->getState($gameId, $u1)['quick_draft']['deck_building']['drafted_cards'],
            'card_id',
        );

        $this->expectException(GameStateException::class);
        $this->expectExceptionMessage('between 12 and 16 cards');

        $this->games->submitDraftDeck($gameId, $u1, array_slice($draftedCardIds, 0, 10));
    }

    /** Regression test: Quick Draft's own minimum deck size matches the physical rules' 12-card floor, same as Winston Draft's. */
    public function testSubmitQuickDraftDeckAcceptsExactlyTheTwelveCardMinimum(): void
    {
        ['gameId' => $gameId, 'u1' => $u1, 'u2' => $u2] = $this->buildQuickDraftFixture();
        $this->driveQuickDraftToDeckBuilding($gameId, $u1, $u2);

        $draftedCardIds = array_column(
            $this->games->getState($gameId, $u1)['quick_draft']['deck_building']['drafted_cards'],
            'card_id',
        );

        $this->games->submitDraftDeck($gameId, $u1, array_slice($draftedCardIds, 0, 12));

        $deckCardIds = $this->games->getState($gameId, $u1)['quick_draft']['deck_building']['deck_card_ids'];
        self::assertCount(12, $deckCardIds);
    }

    public function testSubmitQuickDraftDeckRejectsACardNotInYourDraftedPool(): void
    {
        ['gameId' => $gameId, 'u1' => $u1, 'u2' => $u2] = $this->buildQuickDraftFixture();
        $this->driveQuickDraftToDeckBuilding($gameId, $u1, $u2);

        $draftedCardIds = array_column(
            $this->games->getState($gameId, $u1)['quick_draft']['deck_building']['drafted_cards'],
            'card_id',
        );
        $notDrafted = null;
        for ($cardId = 1; $cardId <= 133; $cardId++) {
            if (!in_array($cardId, $draftedCardIds, true)) {
                $notDrafted = $cardId;
                break;
            }
        }

        $this->expectException(GameStateException::class);
        $this->expectExceptionMessage('can only contain cards you drafted');

        $this->games->submitDraftDeck($gameId, $u1, [...array_slice($draftedCardIds, 0, 13), $notDrafted]);
    }

    public function testGetStateNeverExposesTheOpponentsDraftedOrKeptCards(): void
    {
        ['gameId' => $gameId, 'u1' => $u1, 'u2' => $u2] = $this->buildQuickDraftFixture();
        $this->driveQuickDraftToDeckBuilding($gameId, $u1, $u2);

        $u1State = $this->games->getState($gameId, $u1);
        $u2State = $this->games->getState($gameId, $u2);

        self::assertArrayNotHasKey('opponent_drafted_cards', $u1State['quick_draft']['deck_building']);
        self::assertArrayNotHasKey('opponent_deck_card_ids', $u1State['quick_draft']['deck_building']);

        // Each player's own drafted 16 are theirs alone -- the two hands
        // should essentially never end up identical for a 48-card draft.
        $u1Drafted = array_column($u1State['quick_draft']['deck_building']['drafted_cards'], 'card_id');
        $u2Drafted = array_column($u2State['quick_draft']['deck_building']['drafted_cards'], 'card_id');
        sort($u1Drafted);
        sort($u2Drafted);
        self::assertNotSame($u1Drafted, $u2Drafted);
    }

    public function testQuickDraftMatchProgressesGamesAndCompletesAtTwoWins(): void
    {
        ['gameId' => $gameId, 'u1' => $u1, 'u2' => $u2] = $this->buildQuickDraftFixture(winsNeeded: 1);
        $this->driveQuickDraftToDeckBuilding($gameId, $u1, $u2);
        $this->submitFullQuickDraftDeck($gameId, $u1);
        $this->submitFullQuickDraftDeck($gameId, $u2);
        $this->games->startGame($gameId);

        $draftMatchId = (int) $this->fetchGame($gameId)['draft_match_id'];
        $matchWins = [$u1 => 0, $u2 => 0];
        $currentGameId = $gameId;
        $gamesPlayed = 0;

        while (true) {
            $winnerUserId = $this->completeQuickDraftGameByPassing($currentGameId);
            $matchWins[$winnerUserId]++;
            $gamesPlayed++;
            self::assertLessThanOrEqual(3, $gamesPlayed, 'a best-of-three match can never need a 4th game');

            $match = $this->fetchDraftMatch($draftMatchId);

            if ($matchWins[$winnerUserId] >= 2) {
                self::assertSame('completed', $match['status']);
                self::assertSame($winnerUserId, (int) $match['winner_user_id']);
                self::assertNull(
                    $this->games->getState($currentGameId, $u1)['quick_draft']['next_game_id'],
                    'no next_game_id once the match itself has completed -- there is no next game to link to',
                );
                break;
            }

            self::assertSame('deck_building', $match['status'], 'match resets to deck_building for the next game\'s sideboard step');

            foreach ([$u1, $u2] as $userId) {
                $playerRow = $this->fetchDraftMatchPlayer($draftMatchId, $userId);
                self::assertNull(
                    $playerRow['deck_card_ids'],
                    "user {$userId}'s deck_card_ids must be nulled out so the sideboard step can't be silently skipped",
                );
                self::assertNotNull(
                    $playerRow['previous_deck_card_ids'],
                    "user {$userId}'s previous_deck_card_ids must carry over the deck just used, for the next sideboard's default",
                );
            }

            $nextGameStmt = $this->pdo->prepare(
                "SELECT id, match_game_number FROM games WHERE draft_match_id = :match_id AND status = 'waiting' ORDER BY match_game_number DESC LIMIT 1"
            );
            $nextGameStmt->execute(['match_id' => $draftMatchId]);
            $nextGame = $nextGameStmt->fetch();
            self::assertNotFalse($nextGame, 'the next game in the match should already exist');
            self::assertNotSame($currentGameId, (int) $nextGame['id']);
            self::assertSame($gamesPlayed + 1, (int) $nextGame['match_game_number']);
            self::assertSame(
                $gamesPlayed + 1,
                $this->games->getState((int) $nextGame['id'], $u1)['game']['match_game_number'],
                'getState() must expose match_game_number so the frontend scoreline can show the right game number',
            );
            self::assertSame(
                (int) $nextGame['id'],
                $this->games->getState($currentGameId, $u1)['quick_draft']['next_game_id'],
                'the just-completed game must point at the next game so the board can offer a direct link to it',
            );

            $this->submitFullQuickDraftDeck((int) $nextGame['id'], $u1);
            $this->submitFullQuickDraftDeck((int) $nextGame['id'], $u2);
            $this->games->startGame((int) $nextGame['id']);
            // Round 1 of game 2+ starts frozen until the previous game's
            // loser decides who goes first (see setPlayFirstNextMatchGame())
            // -- resolve it to the default (previous winner goes first
            // again) so completeQuickDraftGameByPassing() can proceed.
            $loserUserId = $winnerUserId === $u1 ? $u2 : $u1;
            $this->games->setPlayFirstNextMatchGame((int) $nextGame['id'], $loserUserId, false);

            $currentGameId = (int) $nextGame['id'];
        }
    }

    /** @return array{gameId: int, u1: int, u2: int, nextGameId: int, winnerUserId: int, loserUserId: int} */
    private function buildQuickDraftMatchThroughGameOne(): array
    {
        ['gameId' => $gameId, 'u1' => $u1, 'u2' => $u2] = $this->buildQuickDraftFixture(winsNeeded: 1);
        $this->driveQuickDraftToDeckBuilding($gameId, $u1, $u2);
        $this->submitFullQuickDraftDeck($gameId, $u1);
        $this->submitFullQuickDraftDeck($gameId, $u2);
        $this->games->startGame($gameId);

        $winnerUserId = $this->completeQuickDraftGameByPassing($gameId);
        $loserUserId = $winnerUserId === $u1 ? $u2 : $u1;

        $draftMatchId = (int) $this->fetchGame($gameId)['draft_match_id'];
        $nextGameStmt = $this->pdo->prepare(
            "SELECT id FROM games WHERE draft_match_id = :match_id AND status = 'waiting' ORDER BY match_game_number DESC LIMIT 1"
        );
        $nextGameStmt->execute(['match_id' => $draftMatchId]);
        $nextGameId = (int) $nextGameStmt->fetchColumn();

        return [
            'gameId' => $gameId,
            'u1' => $u1,
            'u2' => $u2,
            'nextGameId' => $nextGameId,
            'winnerUserId' => $winnerUserId,
            'loserUserId' => $loserUserId,
        ];
    }

    /**
     * buildQuickDraftMatchThroughGameOne() only gets game 2 to 'waiting'
     * (decks not yet submitted) -- per the rules clarification that the
     * loser doesn't have to choose who goes first until they can see
     * their own opening hand, setPlayFirstNextMatchGame() is now only
     * callable once game 2 has actually started, so every test below
     * needs it submitted and started first.
     *
     * @return array{gameId: int, u1: int, u2: int, nextGameId: int, winnerUserId: int, loserUserId: int}
     */
    private function buildQuickDraftMatchAtGameTwoStart(): array
    {
        $fixture = $this->buildQuickDraftMatchThroughGameOne();
        $this->submitFullQuickDraftDeck($fixture['nextGameId'], $fixture['u1']);
        $this->submitFullQuickDraftDeck($fixture['nextGameId'], $fixture['u2']);
        $this->games->startGame($fixture['nextGameId']);
        return $fixture;
    }

    /**
     * The heart of the "loser opts to play first" feature (a follow-up to
     * issue #88/#89's own best-of-three match progression): game 2 starts
     * with round 1 frozen (nobody can act) until the loser decides, and
     * choosing to play first themselves sends them out first once
     * resolved.
     */
    public function testLoserOfPreviousGameCanOptToPlayFirstInNextGame(): void
    {
        [
            'nextGameId' => $nextGameId,
            'winnerUserId' => $winnerUserId, 'loserUserId' => $loserUserId,
        ] = $this->buildQuickDraftMatchAtGameTwoStart();

        $frozenRound = $this->fetchRound($nextGameId);
        self::assertNull($frozenRound['current_turn_game_player_id'], 'round 1 must stay frozen until the loser decides');

        $this->games->setPlayFirstNextMatchGame($nextGameId, $loserUserId, true);

        $round = $this->fetchRound($nextGameId);
        $firstPlayerUserId = (int) $this->pdo->query(
            'SELECT user_id FROM game_players WHERE id = ' . (int) $round['first_game_player_id']
        )->fetchColumn();
        self::assertSame($loserUserId, $firstPlayerUserId);
        self::assertNotSame($winnerUserId, $firstPlayerUserId);
        self::assertSame($round['first_game_player_id'], $round['current_turn_game_player_id'], 'the round unfreezes once decided');
    }

    /** Choosing to let the previous winner go first again is still a real, round-unfreezing decision -- not a no-op. */
    public function testLoserCanChooseToLetThePreviousWinnerGoFirstAgain(): void
    {
        [
            'nextGameId' => $nextGameId,
            'winnerUserId' => $winnerUserId, 'loserUserId' => $loserUserId,
        ] = $this->buildQuickDraftMatchAtGameTwoStart();

        $this->games->setPlayFirstNextMatchGame($nextGameId, $loserUserId, false);

        $round = $this->fetchRound($nextGameId);
        $firstPlayerUserId = (int) $this->pdo->query(
            'SELECT user_id FROM game_players WHERE id = ' . (int) $round['first_game_player_id']
        )->fetchColumn();
        self::assertSame($winnerUserId, $firstPlayerUserId);
        self::assertSame($round['first_game_player_id'], $round['current_turn_game_player_id'], 'the round unfreezes once decided');
    }

    /** The decision gets its own event-log phrasing rather than falling through describeEvent()'s generic "played a card" default. */
    public function testFirstPlayerDecisionGetsItsOwnEventLogPhrasing(): void
    {
        ['nextGameId' => $nextGameId, 'loserUserId' => $loserUserId] = $this->buildQuickDraftMatchAtGameTwoStart();

        $this->games->setPlayFirstNextMatchGame($nextGameId, $loserUserId, true);

        $description = $this->games->getState($nextGameId, $loserUserId)['recent_events'][0]['description'];
        self::assertSame($this->fetchUsername($loserUserId) . ' will go first this game', $description);
    }

    /**
     * Round 1 of game 2+ stays frozen -- not even the acting seat's own
     * placeholder can pass -- until the loser's decision resolves it,
     * mirroring closed_team's own frozen-round-1 pattern.
     */
    public function testRoundStaysFrozenUntilTheLoserDecides(): void
    {
        ['nextGameId' => $nextGameId] = $this->buildQuickDraftMatchAtGameTwoStart();

        $round = $this->fetchRound($nextGameId);
        $placeholderGamePlayerId = (int) $round['first_game_player_id'];

        try {
            $this->games->pass($nextGameId, $placeholderGamePlayerId);
            self::fail('Expected passing before the first-player decision is made to be rejected');
        } catch (GameStateException) {
            // expected
        }
    }

    /** Once decided (either way), the choice is permanent -- there is no changing your mind, unlike the old pre-start checkbox. */
    public function testSetPlayFirstRejectsBeingDecidedTwice(): void
    {
        ['nextGameId' => $nextGameId, 'loserUserId' => $loserUserId] = $this->buildQuickDraftMatchAtGameTwoStart();

        $this->games->setPlayFirstNextMatchGame($nextGameId, $loserUserId, true);

        $this->expectException(GameStateException::class);
        $this->expectExceptionMessage('already been decided');

        $this->games->setPlayFirstNextMatchGame($nextGameId, $loserUserId, false);
    }

    public function testOnlyTheLoserOfThePreviousGameCanSetWhoGoesFirst(): void
    {
        ['nextGameId' => $nextGameId, 'winnerUserId' => $winnerUserId]
            = $this->buildQuickDraftMatchAtGameTwoStart();

        $this->expectException(GameStateException::class);
        $this->expectExceptionMessage('Only the loser');

        $this->games->setPlayFirstNextMatchGame($nextGameId, $winnerUserId, true);
    }

    public function testSetPlayFirstRejectsGameOneOfAMatch(): void
    {
        ['gameId' => $gameId, 'u1' => $u1] = $this->buildQuickDraftFixture(winsNeeded: 2);

        $this->expectException(GameStateException::class);
        $this->expectExceptionMessage('no previous game');

        $this->games->setPlayFirstNextMatchGame($gameId, $u1, true);
    }

    /** Before game 2 has actually started (still deck-building), the loser can't see their opening hand yet, so the choice isn't open yet either. */
    public function testSetPlayFirstRejectsAGameThatHasNotStartedYet(): void
    {
        ['nextGameId' => $nextGameId, 'loserUserId' => $loserUserId] = $this->buildQuickDraftMatchThroughGameOne();

        $this->expectException(GameStateException::class);
        $this->expectExceptionMessage("hasn't started yet");

        $this->games->setPlayFirstNextMatchGame($nextGameId, $loserUserId, true);
    }

    /** Someone not even seated in the match is rejected the same way a non-loser seated player would be. */
    public function testSetPlayFirstRejectsAUserNotSeatedInTheMatch(): void
    {
        ['nextGameId' => $nextGameId] = $this->buildQuickDraftMatchAtGameTwoStart();
        $outsider = $this->insertUser('quickdraft-first-player-outsider');

        $this->expectException(GameStateException::class);
        $this->expectExceptionMessage('Only the loser');

        $this->games->setPlayFirstNextMatchGame($nextGameId, $outsider, true);
    }

    /**
     * getState()'s own 'first_player_decision' field -- only populated
     * once game 2+ has actually started with round 1 still frozen (null
     * for game 1, which has no previous game to base a choice on, and
     * null again once the decision resolves, since the round is no
     * longer frozen at that point).
     */
    public function testGetStateExposesFirstPlayerDecisionWhileRoundOneIsFrozen(): void
    {
        ['gameId' => $gameId, 'u1' => $u1, 'u2' => $u2] = $this->buildQuickDraftFixture(winsNeeded: 1);
        $this->driveQuickDraftToDeckBuilding($gameId, $u1, $u2);
        $this->submitFullQuickDraftDeck($gameId, $u1);
        $this->submitFullQuickDraftDeck($gameId, $u2);
        $this->games->startGame($gameId);
        self::assertNull(
            $this->games->getState($gameId, $u1)['first_player_decision'],
            'game 1 of a match has no previous game to base a choice on',
        );

        $winnerUserId = $this->completeQuickDraftGameByPassing($gameId);
        $loserUserId = $winnerUserId === $u1 ? $u2 : $u1;

        $draftMatchId = (int) $this->fetchGame($gameId)['draft_match_id'];
        $nextGameStmt = $this->pdo->prepare(
            "SELECT id FROM games WHERE draft_match_id = :match_id AND status = 'waiting' ORDER BY match_game_number DESC LIMIT 1"
        );
        $nextGameStmt->execute(['match_id' => $draftMatchId]);
        $nextGameId = (int) $nextGameStmt->fetchColumn();

        $this->submitFullQuickDraftDeck($nextGameId, $u1);
        $this->submitFullQuickDraftDeck($nextGameId, $u2);
        $this->games->startGame($nextGameId);

        $loserDecision = $this->games->getState($nextGameId, $loserUserId)['first_player_decision'];
        self::assertTrue($loserDecision['you_are_previous_loser']);
        self::assertSame($winnerUserId, $loserDecision['default_user_id']);

        $winnerDecision = $this->games->getState($nextGameId, $winnerUserId)['first_player_decision'];
        self::assertFalse($winnerDecision['you_are_previous_loser']);
        self::assertSame($winnerUserId, $winnerDecision['default_user_id']);

        $this->games->setPlayFirstNextMatchGame($nextGameId, $loserUserId, true);

        self::assertNull(
            $this->games->getState($nextGameId, $loserUserId)['first_player_decision'],
            'no longer frozen, so there is nothing left to decide',
        );
        self::assertNull($this->games->getState($nextGameId, $winnerUserId)['first_player_decision']);
    }

    /**
     * A genuinely-trimmed (not full-16) deck must reappear as the new
     * game's previous_deck_card_ids once the match advances, so the
     * frontend's sideboard picker can default to it instead of forcing the
     * player to redo their whole trim from scratch every game.
     */
    public function testPreviousDeckCardIdsCarriesOverForSideboardPrefill(): void
    {
        ['gameId' => $gameId, 'u1' => $u1, 'u2' => $u2] = $this->buildQuickDraftFixture(winsNeeded: 1);
        $this->driveQuickDraftToDeckBuilding($gameId, $u1, $u2);

        $u1Drafted = array_column($this->games->getState($gameId, $u1)['quick_draft']['deck_building']['drafted_cards'], 'card_id');
        $u1Deck = array_slice($u1Drafted, 0, 15);
        $this->games->submitDraftDeck($gameId, $u1, $u1Deck);
        $this->submitFullQuickDraftDeck($gameId, $u2);

        // Before the very first game of a match, there's no previous deck
        // to fall back to yet -- confirms this doesn't false-positive on a
        // stale value from some earlier match.
        self::assertNull($this->games->getState($gameId, $u1)['quick_draft']['deck_building']['previous_deck_card_ids']);

        $this->games->startGame($gameId);
        $this->completeQuickDraftGameByPassing($gameId);

        $draftMatchId = (int) $this->fetchGame($gameId)['draft_match_id'];
        $nextGameStmt = $this->pdo->prepare(
            "SELECT id FROM games WHERE draft_match_id = :match_id AND status = 'waiting' ORDER BY match_game_number DESC LIMIT 1"
        );
        $nextGameStmt->execute(['match_id' => $draftMatchId]);
        $nextGameId = (int) $nextGameStmt->fetchColumn();

        $deckBuilding = $this->games->getState($nextGameId, $u1)['quick_draft']['deck_building'];
        self::assertNull(
            $deckBuilding['deck_card_ids'],
            'this new game\'s own deck_card_ids must still be null -- previous_deck_card_ids is only a prefill hint',
        );
        $previousDeck = $deckBuilding['previous_deck_card_ids'];
        sort($u1Deck);
        sort($previousDeck);
        self::assertSame($u1Deck, $previousDeck, 'previous_deck_card_ids must be exactly the 15-card deck just used, not all 16 drafted cards');
        self::assertNotSame($u1Drafted, $previousDeck, 'sanity check: the trimmed deck actually differs from the full drafted pool');
    }

    /**
     * listGamesForUser()'s draft_match_id/draft_match fields let the
     * lobby group a match's up-to-3 games together and, once it's done,
     * show the result -- see draftMatchSummaryFor().
     */
    public function testListGamesForUserGroupsQuickDraftMatchGamesAndSummarizesTheResultOnceCompleted(): void
    {
        ['gameId' => $gameId, 'u1' => $u1, 'u2' => $u2] = $this->buildQuickDraftFixture(winsNeeded: 1);
        $this->driveQuickDraftToDeckBuilding($gameId, $u1, $u2);
        $this->submitFullQuickDraftDeck($gameId, $u1);
        $this->submitFullQuickDraftDeck($gameId, $u2);
        $this->games->startGame($gameId);

        $draftMatchId = (int) $this->fetchGame($gameId)['draft_match_id'];

        $summaryFor = function (int $userId) use ($gameId): array {
            foreach ($this->games->listGamesForUser($userId) as $summary) {
                if ($summary['id'] === $gameId) {
                    return $summary;
                }
            }
            self::fail("game {$gameId} missing from listGamesForUser()");
        };

        $u1Summary = $summaryFor($u1);
        self::assertSame($draftMatchId, $u1Summary['draft_match_id']);
        self::assertSame(1, $u1Summary['match_game_number']);
        // driveQuickDraftToDeckBuilding() above already ran the draft to
        // completion, so the match is already at 'deck_building' by here.
        self::assertSame('deck_building', $u1Summary['draft_match']['status']);
        self::assertSame(0, $u1Summary['draft_match']['your_wins']);
        self::assertSame(0, $u1Summary['draft_match']['opponent_wins']);
        self::assertSame(2, $u1Summary['draft_match']['games_to_win']);
        self::assertNull($u1Summary['draft_match']['winner_username']);

        // Drive the match to completion the same way
        // testQuickDraftMatchProgressesGamesAndCompletesAtTwoWins() does --
        // completeQuickDraftGameByPassing() hands each game to whichever
        // seat went first that round, not necessarily the same user twice
        // in a row, so this can't assume a clean 2-0 for either side.
        $matchWins = [$u1 => 0, $u2 => 0];
        $currentGameId = $gameId;
        for ($gamesPlayed = 0; $gamesPlayed < 3; $gamesPlayed++) {
            $winnerUserId = $this->completeQuickDraftGameByPassing($currentGameId);
            $matchWins[$winnerUserId]++;

            $summaryForCurrent = function (int $userId) use ($currentGameId): array {
                foreach ($this->games->listGamesForUser($userId) as $summary) {
                    if ($summary['id'] === $currentGameId) {
                        return $summary;
                    }
                }
                self::fail("game {$currentGameId} missing from listGamesForUser()");
            };

            if ($matchWins[$winnerUserId] >= 2) {
                $winnerUsername = $this->fetchUsername($winnerUserId);
                $loserUserId = $winnerUserId === $u1 ? $u2 : $u1;

                $finalWinnerSummary = $summaryForCurrent($winnerUserId);
                self::assertSame($draftMatchId, $finalWinnerSummary['draft_match_id']);
                self::assertSame('completed', $finalWinnerSummary['draft_match']['status']);
                self::assertSame(2, $finalWinnerSummary['draft_match']['your_wins']);
                self::assertSame($matchWins[$loserUserId], $finalWinnerSummary['draft_match']['opponent_wins']);
                self::assertSame($winnerUsername, $finalWinnerSummary['draft_match']['winner_username']);

                $finalLoserSummary = $summaryForCurrent($loserUserId);
                self::assertSame('completed', $finalLoserSummary['draft_match']['status']);
                self::assertSame($matchWins[$loserUserId], $finalLoserSummary['draft_match']['your_wins']);
                self::assertSame(2, $finalLoserSummary['draft_match']['opponent_wins']);
                self::assertSame($winnerUsername, $finalLoserSummary['draft_match']['winner_username'], 'winner_username names the actual winner regardless of which side is asking');

                return;
            }

            // Match isn't done yet -- the next game already exists and
            // shares the same draft_match_id, so the lobby can still group
            // them even though this row's own status just flipped to
            // 'completed'.
            $winnerSummary = $summaryForCurrent($winnerUserId);
            self::assertSame('deck_building', $winnerSummary['draft_match']['status']);
            self::assertNull($winnerSummary['draft_match']['winner_username'], 'match not over yet');

            $nextGameStmt = $this->pdo->prepare(
                "SELECT id FROM games WHERE draft_match_id = :match_id AND status = 'waiting' ORDER BY match_game_number DESC LIMIT 1"
            );
            $nextGameStmt->execute(['match_id' => $draftMatchId]);
            $nextGameId = (int) $nextGameStmt->fetchColumn();

            $nextGameSummaryFor = function (int $userId) use ($nextGameId): array {
                foreach ($this->games->listGamesForUser($userId) as $summary) {
                    if ($summary['id'] === $nextGameId) {
                        return $summary;
                    }
                }
                self::fail("game {$nextGameId} missing from listGamesForUser()");
            };
            self::assertSame($draftMatchId, $nextGameSummaryFor($winnerUserId)['draft_match_id'], 'the next game in the match shares the same draft_match_id, so the lobby can group it with the previous one');

            $this->submitFullQuickDraftDeck($nextGameId, $u1);
            $this->submitFullQuickDraftDeck($nextGameId, $u2);
            $this->games->startGame($nextGameId);
            // Round 1 of game 2+ starts frozen until the previous game's
            // loser decides who goes first -- resolve to the default so
            // completeQuickDraftGameByPassing() can proceed.
            $loserUserId = $winnerUserId === $u1 ? $u2 : $u1;
            $this->games->setPlayFirstNextMatchGame($nextGameId, $loserUserId, false);
            $currentGameId = $nextGameId;
        }

        self::fail('a best-of-three match can never need a 4th game');
    }

    /**
     * The pre-game analog of the isAwaitingResponseFrom()-driven lobby
     * icons for an already-'in_progress' game (see
     * testListGamesForUserExposesCurrentTurnAndAwaitingResponseUsernamesForEveryPlayer())
     * -- while a quick_draft game is still 'waiting', awaiting_response_usernames
     * instead names whoever draftAwaitingResponseUsernames() finds still
     * owes an action for the CURRENT stage of the current round: both
     * players until their own draw-stage pick is submitted, then just
     * whoever's outstanding, then both again once the received stage
     * opens up (only possible once BOTH have submitted the draw stage),
     * then back down to whoever's outstanding there too. current_turn_username
     * stays null throughout -- a still-'waiting' draft game has no board
     * "turn" concept yet.
     */
    public function testListGamesForUserShowsAwaitingResponseUsernamesDuringQuickDraftDrafting(): void
    {
        ['gameId' => $gameId, 'u1' => $u1, 'u2' => $u2] = $this->buildQuickDraftFixture();
        $u1Username = $this->fetchUsername($u1);
        $u2Username = $this->fetchUsername($u2);

        $summaryFor = function (int $userId) use ($gameId): array {
            foreach ($this->games->listGamesForUser($userId) as $summary) {
                if ($summary['id'] === $gameId) {
                    return $summary;
                }
            }
            self::fail("game {$gameId} missing from listGamesForUser()");
        };

        $summary = $summaryFor($u1);
        self::assertNull($summary['current_turn_username']);
        self::assertSame([$u1Username, $u2Username], $summary['awaiting_response_usernames'], 'nobody has submitted a draw-stage pick yet -- both owe one');
        self::assertTrue($summaryFor($u1)['is_awaiting_your_response']);
        self::assertTrue($summaryFor($u2)['is_awaiting_your_response']);

        $pack = $this->games->getState($gameId, $u1)['quick_draft']['drafting']['pack'];
        $this->games->submitQuickDraftPick($gameId, $u1, 1, 'draw', [$pack[0]['card_id'], $pack[1]['card_id']]);

        self::assertSame([$u2Username], $summaryFor($u1)['awaiting_response_usernames'], 'u1 already submitted this round\'s draw stage -- only u2 is still owed');
        self::assertFalse($summaryFor($u1)['is_awaiting_your_response']);
        self::assertTrue($summaryFor($u2)['is_awaiting_your_response']);

        $pack = $this->games->getState($gameId, $u2)['quick_draft']['drafting']['pack'];
        $this->games->submitQuickDraftPick($gameId, $u2, 1, 'draw', [$pack[0]['card_id'], $pack[1]['card_id']]);

        self::assertSame([$u1Username, $u2Username], $summaryFor($u1)['awaiting_response_usernames'], 'both have their draw-stage picks in -- the received stage is now open and owed by both');

        $pack = $this->games->getState($gameId, $u1)['quick_draft']['drafting']['pack'];
        $this->games->submitQuickDraftPick($gameId, $u1, 1, 'received', [$pack[0]['card_id'], $pack[1]['card_id']]);

        self::assertSame([$u2Username], $summaryFor($u1)['awaiting_response_usernames'], 'u1 finished round 1 -- only u2 is still owed the received stage');
    }

    /**
     * Once quick_draft's own 4 rounds finish, the match moves to
     * 'deck_building' but the game itself stays 'waiting' until both
     * players submit a deck (see requireDraftDecksSubmitted()) --
     * awaiting_response_usernames tracks that instead during this phase,
     * reading draft_match_players.deck_card_ids directly rather than any
     * round-pick data.
     */
    public function testListGamesForUserShowsAwaitingResponseUsernamesDuringQuickDraftDeckBuilding(): void
    {
        ['gameId' => $gameId, 'u1' => $u1, 'u2' => $u2] = $this->buildQuickDraftFixture();
        $u1Username = $this->fetchUsername($u1);
        $u2Username = $this->fetchUsername($u2);
        $this->driveQuickDraftToDeckBuilding($gameId, $u1, $u2);

        $summaryFor = function (int $userId) use ($gameId): array {
            foreach ($this->games->listGamesForUser($userId) as $summary) {
                if ($summary['id'] === $gameId) {
                    return $summary;
                }
            }
            self::fail("game {$gameId} missing from listGamesForUser()");
        };

        self::assertSame([$u1Username, $u2Username], $summaryFor($u1)['awaiting_response_usernames'], 'neither has submitted a deck yet');

        $this->submitFullQuickDraftDeck($gameId, $u1);

        self::assertSame([$u2Username], $summaryFor($u1)['awaiting_response_usernames']);
        self::assertFalse($summaryFor($u1)['is_awaiting_your_response']);
        self::assertTrue($summaryFor($u2)['is_awaiting_your_response']);

        $this->submitFullQuickDraftDeck($gameId, $u2);

        self::assertSame([], $summaryFor($u1)['awaiting_response_usernames'], 'both have submitted -- the game is ready for startGame(), nobody left to wait on');
    }

    // -- Winston Draft (issue #89) -------------------------------------------

    /** @return array{gameId:int, u1:int, u2:int} */
    private function buildWinstonDraftFixture(
        string $poolSource = 'random_48',
        ?string $customPoolText = null,
        int $winsNeeded = 1,
    ): array {
        $u1 = $this->insertUser('winstondraft-' . uniqid('u1'));
        $u2 = $this->insertUser('winstondraft-' . uniqid('u2'));

        $gameId = $this->games->createGame(
            $u1,
            [$u1, $u2],
            format: 'draft',
            winsNeeded: $winsNeeded,
            deckType: 'winston_draft',
            winstonDraftPoolSource: $poolSource,
            winstonDraftCustomPoolText: $customPoolText,
        );

        return ['gameId' => $gameId, 'u1' => $u1, 'u2' => $u2];
    }

    private function fetchWinstonState(int $draftMatchId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM draft_winston_state WHERE draft_match_id = :id');
        $stmt->execute(['id' => $draftMatchId]);

        return $stmt->fetch();
    }

    /**
     * Drives a Winston Draft to completion for both $u1/$u2 via a simple
     * deterministic policy, exercised purely through the public API
     * (submitWinstonDraftPick()/getState()), the same surface the real
     * frontend uses: take the currently-active pile once it has at least
     * 2 cards, or -- once the shared deck is exhausted, since nothing
     * will ever grow again from that point on -- take it as soon as it
     * has at least 1. Otherwise pass. This guarantees every pile
     * eventually gets taken by whichever player's turn next lands on it
     * (current_pile_number always resets to 1 at the start of a turn),
     * so the draft always terminates rather than stalling forever on a
     * pile stuck below a fixed threshold with nothing left to grow it.
     * Capped at a generous iteration count purely as a safety net against
     * an infinite-loop regression, not because the real mechanic could
     * plausibly need this many turns for a single 45-card pool.
     */
    private function driveWinstonDraftToDeckBuilding(int $gameId, int $u1, int $u2): void
    {
        for ($i = 0; $i < 500; $i++) {
            $state = $this->games->getState($gameId, $u1);
            $winston = $state['winston_draft'];
            if ($winston['status'] !== 'drafting') {
                return;
            }

            $drafting = $winston['drafting'];
            $currentUserId = $drafting['is_your_turn'] ? $u1 : $u2;
            $currentPileSize = $drafting['pile_sizes'][$drafting['current_pile_number'] - 1];
            $deckExhausted = $drafting['remaining_deck_count'] === 0;

            $action = ($currentPileSize >= 2 || ($deckExhausted && $currentPileSize >= 1)) ? 'take' : 'pass';
            $this->games->submitWinstonDraftPick($gameId, $currentUserId, $action);
        }

        self::fail('Winston Draft did not complete within 500 picks -- possible infinite loop');
    }

    /** Submits all of $userId's own drafted cards as their deck (the max end of the open-ended range). */
    private function submitFullWinstonDraftDeck(int $gameId, int $userId): void
    {
        $state = $this->games->getState($gameId, $userId);
        $cardIds = array_column($state['winston_draft']['deck_building']['drafted_cards'], 'card_id');
        self::assertGreaterThanOrEqual(12, count($cardIds));

        $this->games->submitDraftDeck($gameId, $userId, $cardIds);
    }

    public function testCreateGameRejectsWinstonDraftForNonDraftFormat(): void
    {
        $creator = $this->insertUser('winstondraft-nondraft-alice');
        $bob = $this->insertUser('winstondraft-nondraft-bob');

        $this->expectException(GameStateException::class);
        $this->expectExceptionMessage('only supported for the "draft" format');

        $this->games->createGame($creator, [$creator, $bob], deckType: 'winston_draft', winstonDraftPoolSource: 'random_48');
    }

    public function testCreateGameWinstonDraftPoolIsTruncatedToFortyFive(): void
    {
        $fixture = $this->buildWinstonDraftFixture('one_of_each');

        $draftMatchId = (int) $this->fetchGame($fixture['gameId'])['draft_match_id'];
        $match = $this->fetchDraftMatch($draftMatchId);

        self::assertCount(45, json_decode((string) $match['pool_card_ids'], true), 'one_of_each\'s 133 cards are randomly narrowed down to 45 before the draft begins, same as Quick Draft\'s own 48-card cap');
    }

    public function testCreateGameWinstonDraftCustomPoolBelowMinimumIsRejected(): void
    {
        $creator = $this->insertUser('winstondraft-undersized-alice');
        $bob = $this->insertUser('winstondraft-undersized-bob');

        $this->expectException(GameStateException::class);
        $this->expectExceptionMessage('at least 45 are required');

        $this->games->createGame(
            $creator,
            [$creator, $bob],
            format: 'draft',
            deckType: 'winston_draft',
            winstonDraftPoolSource: 'custom',
            winstonDraftCustomPoolText: "20 Charity\n",
        );
    }

    public function testWinstonDraftDealsThreeSingleCardPilesAndRandomlyPicksFirstPlayer(): void
    {
        $fixture = $this->buildWinstonDraftFixture();
        $gameId = $fixture['gameId'];
        $u1 = $fixture['u1'];
        $u2 = $fixture['u2'];

        $draftMatchId = (int) $this->fetchGame($gameId)['draft_match_id'];
        $winstonState = $this->fetchWinstonState($draftMatchId);

        self::assertCount(1, json_decode((string) $winstonState['pile_1_card_ids'], true));
        self::assertCount(1, json_decode((string) $winstonState['pile_2_card_ids'], true));
        self::assertCount(1, json_decode((string) $winstonState['pile_3_card_ids'], true));
        self::assertCount(42, json_decode((string) $winstonState['remaining_deck_card_ids'], true), '45-card pool minus 1 card dealt to each of the 3 piles');
        self::assertContains((int) $winstonState['current_player_user_id'], [$u1, $u2]);
        self::assertSame(1, (int) $winstonState['current_pile_number']);

        $state = $this->games->getState($gameId, $u1);
        self::assertSame('drafting', $state['winston_draft']['status']);
        self::assertSame(3, count($state['winston_draft']['drafting']['pile_sizes']));
        self::assertSame(42, $state['winston_draft']['drafting']['remaining_deck_count']);
    }

    public function testWinstonDraftRejectsAPickFromWhoeverIsNotTheCurrentPlayer(): void
    {
        $fixture = $this->buildWinstonDraftFixture();
        $gameId = $fixture['gameId'];
        $u1 = $fixture['u1'];
        $u2 = $fixture['u2'];

        $draftMatchId = (int) $this->fetchGame($gameId)['draft_match_id'];
        $currentPlayerUserId = (int) $this->fetchWinstonState($draftMatchId)['current_player_user_id'];
        $otherUserId = $currentPlayerUserId === $u1 ? $u2 : $u1;

        $this->expectException(GameStateException::class);
        $this->expectExceptionMessage("It's not your turn");

        $this->games->submitWinstonDraftPick($gameId, $otherUserId, 'pass');
    }

    public function testWinstonDraftRejectsAnInvalidAction(): void
    {
        $fixture = $this->buildWinstonDraftFixture();
        $gameId = $fixture['gameId'];

        $draftMatchId = (int) $this->fetchGame($gameId)['draft_match_id'];
        $currentPlayerUserId = (int) $this->fetchWinstonState($draftMatchId)['current_player_user_id'];

        $this->expectException(GameStateException::class);
        $this->expectExceptionMessage('action must be "take" or "pass"');

        $this->games->submitWinstonDraftPick($gameId, $currentPlayerUserId, 'steal');
    }

    public function testWinstonDraftOnlyRevealsTheActivePileToTheCurrentPlayer(): void
    {
        $fixture = $this->buildWinstonDraftFixture();
        $gameId = $fixture['gameId'];
        $u1 = $fixture['u1'];
        $u2 = $fixture['u2'];

        $draftMatchId = (int) $this->fetchGame($gameId)['draft_match_id'];
        $currentPlayerUserId = (int) $this->fetchWinstonState($draftMatchId)['current_player_user_id'];
        $otherUserId = $currentPlayerUserId === $u1 ? $u2 : $u1;

        $currentPlayerState = $this->games->getState($gameId, $currentPlayerUserId)['winston_draft']['drafting'];
        self::assertTrue($currentPlayerState['is_your_turn']);
        self::assertCount(1, $currentPlayerState['current_pile_cards'], 'the active player sees the pile they are actually looking at');

        $otherPlayerState = $this->games->getState($gameId, $otherUserId)['winston_draft']['drafting'];
        self::assertFalse($otherPlayerState['is_your_turn']);
        self::assertSame([], $otherPlayerState['current_pile_cards'], 'the opponent never sees the active player\'s own pile contents, only its size');
        self::assertSame($currentPlayerState['pile_sizes'], $otherPlayerState['pile_sizes'], 'pile sizes -- unlike contents -- are visible to both, like a real face-down stack\'s height');
    }

    /**
     * opponent_last_take_pile_number/opponent_last_drew_from_deck/
     * opponent_drafted_card_count never reveal card identities -- only
     * which numbered pile the opponent most recently TOOK (never a pass),
     * or that they instead declined all 3 piles and drew from the deck,
     * and how many cards they've drafted in total. Since turns strictly
     * alternate and either player can pass any number of times before
     * eventually ending their turn, "the opponent's last action" has to be
     * tracked per-user_id (draft_winston_state.
     * last_draft_action_by_user_id) rather than a single shared "whoever
     * acted last" value -- this drives both players taking different
     * piles on different turns and confirms each one's own view stays
     * independently correct throughout.
     */
    public function testWinstonDraftExposesEachPlayersOwnLastTakenPileAndTotalDraftedCountToTheOpponent(): void
    {
        $fixture = $this->buildWinstonDraftFixture();
        $gameId = $fixture['gameId'];
        $u1 = $fixture['u1'];
        $u2 = $fixture['u2'];

        $draftMatchId = (int) $this->fetchGame($gameId)['draft_match_id'];
        $firstPlayerUserId = (int) $this->fetchWinstonState($draftMatchId)['current_player_user_id'];
        $secondPlayerUserId = $firstPlayerUserId === $u1 ? $u2 : $u1;

        // Before anyone has taken anything, neither player has a last-take
        // pile, and neither has drafted any cards yet.
        $firstPlayerState = $this->games->getState($gameId, $firstPlayerUserId)['winston_draft']['drafting'];
        self::assertNull($firstPlayerState['opponent_last_take_pile_number']);
        self::assertFalse($firstPlayerState['opponent_last_drew_from_deck']);
        self::assertSame(0, $firstPlayerState['opponent_drafted_card_count']);

        // Player 1 passes pile 1 (a non-take action) then takes pile 2 --
        // taking always ends the turn regardless of which pile it's on.
        $this->games->submitWinstonDraftPick($gameId, $firstPlayerUserId, 'pass');
        $this->games->submitWinstonDraftPick($gameId, $firstPlayerUserId, 'take');

        // From player 2's perspective, player 1's own last take (pile 2)
        // is now visible, and their drafted count reflects that one pile.
        $secondPlayerState = $this->games->getState($gameId, $secondPlayerUserId)['winston_draft']['drafting'];
        self::assertSame(2, $secondPlayerState['opponent_last_take_pile_number']);
        self::assertFalse($secondPlayerState['opponent_last_drew_from_deck']);
        self::assertSame(1, $secondPlayerState['opponent_drafted_card_count']);

        // Player 1's own view of "the opponent" (player 2) is still
        // untouched -- player 2 hasn't taken anything yet.
        $firstPlayerState = $this->games->getState($gameId, $firstPlayerUserId)['winston_draft']['drafting'];
        self::assertNull($firstPlayerState['opponent_last_take_pile_number']);
        self::assertFalse($firstPlayerState['opponent_last_drew_from_deck']);
        self::assertSame(0, $firstPlayerState['opponent_drafted_card_count']);

        // Player 2 takes pile 1 immediately (no pass) -- pile 1 now holds 2
        // cards (its original 1 plus the 1 it grew by by from player 1's
        // earlier pass), so player 2's own drafted count is 2, not 1.
        $this->games->submitWinstonDraftPick($gameId, $secondPlayerUserId, 'take');

        // Now player 1 sees player 2's own last take (pile 1) -- and
        // player 2's own view of player 1 is unaffected by their own action.
        $firstPlayerState = $this->games->getState($gameId, $firstPlayerUserId)['winston_draft']['drafting'];
        self::assertSame(1, $firstPlayerState['opponent_last_take_pile_number']);
        self::assertFalse($firstPlayerState['opponent_last_drew_from_deck']);
        self::assertSame(2, $firstPlayerState['opponent_drafted_card_count']);

        $secondPlayerState = $this->games->getState($gameId, $secondPlayerUserId)['winston_draft']['drafting'];
        self::assertSame(2, $secondPlayerState['opponent_last_take_pile_number'], 'player 1\'s own last take is still pile 2 from before -- unaffected by player 2\'s own turn');
        self::assertFalse($secondPlayerState['opponent_last_drew_from_deck']);
        self::assertSame(1, $secondPlayerState['opponent_drafted_card_count']);

        // Player 1 now declines all 3 piles in a row, triggering the
        // mandatory top-of-deck auto-draw -- this also ends their turn,
        // just like a take, but should read as "drew from the deck"
        // rather than showing a stale pile number from their earlier take.
        $this->games->submitWinstonDraftPick($gameId, $firstPlayerUserId, 'pass');
        $this->games->submitWinstonDraftPick($gameId, $firstPlayerUserId, 'pass');
        $this->games->submitWinstonDraftPick($gameId, $firstPlayerUserId, 'pass');

        $secondPlayerState = $this->games->getState($gameId, $secondPlayerUserId)['winston_draft']['drafting'];
        self::assertNull($secondPlayerState['opponent_last_take_pile_number'], 'player 1\'s last action was a deck draw, not a take, so no pile number is reported');
        self::assertTrue($secondPlayerState['opponent_last_drew_from_deck']);
    }

    /**
     * Directly exercises the one specific edge case in submitWinstonDraftPick()'s
     * own docblock: declining pile 3 replenishes it FIRST (consuming
     * whatever's left of the deck), and only THEN attempts the mandatory
     * auto-draw from whatever remains -- so if the deck has exactly 1
     * card left, that card goes to pile 3's own replenish, and the
     * auto-draw gets nothing at all that turn. State is seeded directly
     * (rather than driven organically through 45 cards of shuffled play)
     * so this exact deck-size boundary is hit deterministically.
     */
    public function testWinstonDraftPileThreeReplenishConsumesLastCardBeforeAutoDraw(): void
    {
        $fixture = $this->buildWinstonDraftFixture();
        $gameId = $fixture['gameId'];
        $u1 = $fixture['u1'];
        $u2 = $fixture['u2'];

        $draftMatchId = (int) $this->fetchGame($gameId)['draft_match_id'];
        $currentPlayerUserId = (int) $this->fetchWinstonState($draftMatchId)['current_player_user_id'];

        $this->pdo->prepare(
            'UPDATE draft_winston_state
             SET remaining_deck_card_ids = :deck, pile_1_card_ids = :pile1, pile_2_card_ids = :pile2, pile_3_card_ids = :pile3, current_pile_number = 3
             WHERE draft_match_id = :match_id'
        )->execute([
            'deck' => json_encode([99]),
            'pile1' => json_encode([]),
            'pile2' => json_encode([]),
            'pile3' => json_encode([50]),
            'match_id' => $draftMatchId,
        ]);

        $beforeDrafted = json_decode(
            (string) $this->fetchDraftMatchPlayer($draftMatchId, $currentPlayerUserId)['drafted_card_ids'],
            true
        );

        $result = $this->games->submitWinstonDraftPick($gameId, $currentPlayerUserId, 'pass');
        self::assertFalse($result['draft_completed'], 'pile 3 now holds 2 undrafted cards -- the draft only ends once every card has actually been TAKEN by a player, not merely moved between the deck and a pile');

        $afterDrafted = json_decode(
            (string) $this->fetchDraftMatchPlayer($draftMatchId, $currentPlayerUserId)['drafted_card_ids'],
            true
        );
        self::assertSame($beforeDrafted, $afterDrafted, 'the last deck card went to replenishing pile 3, not to the acting player -- the auto-draw found nothing left');

        $winstonState = $this->fetchWinstonState($draftMatchId);
        self::assertSame([], json_decode((string) $winstonState['remaining_deck_card_ids'], true));
        self::assertEqualsCanonicalizing([50, 99], json_decode((string) $winstonState['pile_3_card_ids'], true), 'pile 3 grew from the replenish, not from a second auto-draw');
    }

    public function testWinstonDraftProceedsToDeckBuildingAndConservesEveryPoolCard(): void
    {
        $fixture = $this->buildWinstonDraftFixture();
        $gameId = $fixture['gameId'];
        $u1 = $fixture['u1'];
        $u2 = $fixture['u2'];
        $draftMatchId = (int) $this->fetchGame($gameId)['draft_match_id'];

        $this->driveWinstonDraftToDeckBuilding($gameId, $u1, $u2);

        $u1Cards = json_decode((string) $this->fetchDraftMatchPlayer($draftMatchId, $u1)['drafted_card_ids'], true);
        $u2Cards = json_decode((string) $this->fetchDraftMatchPlayer($draftMatchId, $u2)['drafted_card_ids'], true);
        self::assertSame(45, count($u1Cards) + count($u2Cards), 'every card in the 45-card pool ends up with exactly one of the two players -- none are ever discarded in Winston Draft, unlike Quick Draft');

        $match = $this->fetchDraftMatch($draftMatchId);
        if ($match['status'] === 'deck_building') {
            // The expected/normal outcome for this deterministic policy.
            self::assertGreaterThanOrEqual(12, count($u1Cards));
            self::assertGreaterThanOrEqual(12, count($u2Cards));
        } elseif ($match['status'] === 'completed') {
            // Only possible if the random shuffle happened to leave one
            // side short of WINSTON_MIN_DECK_SIZE -- see
            // testWinstonDraftAutoLosesPlayerWithFewerThanTwelveDraftedCards()
            // for a deterministic exercise of this same path.
            $shortUserId = count($u1Cards) < 12 ? $u1 : $u2;
            $winnerUserId = $shortUserId === $u1 ? $u2 : $u1;
            self::assertSame($winnerUserId, (int) $match['winner_user_id']);
        } else {
            self::fail("unexpected draft_matches.status \"{$match['status']}\" after the draft completed");
        }
    }

    public function testWinstonDraftAutoLosesPlayerWithFewerThanTwelveDraftedCards(): void
    {
        $fixture = $this->buildWinstonDraftFixture();
        $gameId = $fixture['gameId'];
        $u1 = $fixture['u1'];
        $u2 = $fixture['u2'];
        $draftMatchId = (int) $this->fetchGame($gameId)['draft_match_id'];

        // Seed a lopsided split directly rather than relying on chance --
        // u1 ends up with only 5 cards (short of WINSTON_MIN_DECK_SIZE),
        // u2 with the other 40, for a pool of 45 total.
        $u1CardIds = range(1, 5);
        $u2CardIds = range(6, 45);
        $this->pdo->prepare('UPDATE draft_match_players SET drafted_card_ids = :ids WHERE draft_match_id = :match_id AND user_id = :user_id')
            ->execute(['ids' => json_encode($u1CardIds), 'match_id' => $draftMatchId, 'user_id' => $u1]);
        $this->pdo->prepare('UPDATE draft_match_players SET drafted_card_ids = :ids WHERE draft_match_id = :match_id AND user_id = :user_id')
            ->execute(['ids' => json_encode($u2CardIds), 'match_id' => $draftMatchId, 'user_id' => $u2]);

        // Force the very next pick to be the one that empties the deck
        // and all 3 piles simultaneously, triggering finalizeWinstonDraft().
        $currentPlayerUserId = (int) $this->fetchWinstonState($draftMatchId)['current_player_user_id'];
        $this->pdo->prepare(
            'UPDATE draft_winston_state
             SET remaining_deck_card_ids = :deck, pile_1_card_ids = :pile1, pile_2_card_ids = :pile2, pile_3_card_ids = :pile3, current_pile_number = 1
             WHERE draft_match_id = :match_id'
        )->execute([
            'deck' => json_encode([]),
            'pile1' => json_encode([50]),
            'pile2' => json_encode([]),
            'pile3' => json_encode([]),
            'match_id' => $draftMatchId,
        ]);

        $result = $this->games->submitWinstonDraftPick($gameId, $currentPlayerUserId, 'take');
        self::assertTrue($result['draft_completed']);

        $match = $this->fetchDraftMatch($draftMatchId);
        self::assertSame('completed', $match['status'], 'the whole match completes immediately -- no deck_building/sideboard step, no further games');
        self::assertSame($u2, (int) $match['winner_user_id'], 'u1 never even reaches 12 total drafted cards (5, plus whatever this last pick added), so u2 automatically wins');

        $gameRow = $this->fetchGame($gameId);
        self::assertSame('abandoned', $gameRow['status'], 'the match\'s own already-created game 1 is marked abandoned rather than left stuck in \'waiting\' forever -- no games are actually played');
    }

    public function testWinstonDraftMatchProgressesGamesAndCompletesAtTwoWins(): void
    {
        $fixture = $this->buildWinstonDraftFixture(winsNeeded: 1);
        $gameId = $fixture['gameId'];
        $u1 = $fixture['u1'];
        $u2 = $fixture['u2'];
        $draftMatchId = (int) $this->fetchGame($gameId)['draft_match_id'];

        $this->driveWinstonDraftToDeckBuilding($gameId, $u1, $u2);

        $match = $this->fetchDraftMatch($draftMatchId);
        if ($match['status'] !== 'deck_building') {
            self::markTestSkipped('this particular shuffle produced an auto-loss before any games were played -- see testWinstonDraftAutoLosesPlayerWithFewerThanTwelveDraftedCards() for a deterministic exercise of that path');
        }

        $this->submitFullWinstonDraftDeck($gameId, $u1);
        $this->submitFullWinstonDraftDeck($gameId, $u2);
        $this->games->startGame($gameId);

        // completeQuickDraftGameByPassing() is itself format-agnostic (it
        // only ever calls pass() twice against whatever game id it's
        // given) -- reused here unchanged for Winston Draft's own
        // best-of-three progression.
        $matchWins = [$u1 => 0, $u2 => 0];
        $currentGameId = $gameId;
        for ($gamesPlayed = 0; $gamesPlayed < 3; $gamesPlayed++) {
            $winnerUserId = $this->completeQuickDraftGameByPassing($currentGameId);
            $matchWins[$winnerUserId]++;

            if ($matchWins[$winnerUserId] >= 2) {
                $finalMatch = $this->fetchDraftMatch($draftMatchId);
                self::assertSame('completed', $finalMatch['status']);
                self::assertSame($winnerUserId, (int) $finalMatch['winner_user_id']);

                return;
            }

            $nextGameStmt = $this->pdo->prepare(
                "SELECT id FROM games WHERE draft_match_id = :match_id AND status = 'waiting' ORDER BY match_game_number DESC LIMIT 1"
            );
            $nextGameStmt->execute(['match_id' => $draftMatchId]);
            $nextGameId = (int) $nextGameStmt->fetchColumn();
            self::assertSame($fixture['gameId'] !== $nextGameId, true);

            $nextGameRow = $this->fetchGame($nextGameId);
            self::assertSame('winston_draft', $nextGameRow['deck_type'], 'the next game in the match keeps the same deck_type -- regression test for the deck_type hardcoding bug fixed alongside this feature');

            $this->submitFullWinstonDraftDeck($nextGameId, $u1);
            $this->submitFullWinstonDraftDeck($nextGameId, $u2);
            $this->games->startGame($nextGameId);
            // Round 1 of game 2+ starts frozen until the previous game's
            // loser decides who goes first -- resolve to the default so
            // completeQuickDraftGameByPassing() can proceed.
            $loserUserId = $winnerUserId === $u1 ? $u2 : $u1;
            $this->games->setPlayFirstNextMatchGame($nextGameId, $loserUserId, false);
            $currentGameId = $nextGameId;
        }

        self::fail('a best-of-three match can never need a 4th game');
    }

    /**
     * Unlike quick_draft's own simultaneous-blind picks, winston_draft is
     * strictly single-active-player -- draftAwaitingResponseUsernames()
     * names exactly whoever draft_winston_state.current_player_user_id
     * currently is, never both at once. current_turn_username stays
     * null throughout -- a still-'waiting' draft game has no board
     * "turn" concept yet, only the pre-game equivalent exposed here.
     */
    public function testListGamesForUserShowsAwaitingResponseUsernameForWinstonDraftCurrentPlayer(): void
    {
        ['gameId' => $gameId, 'u1' => $u1, 'u2' => $u2] = $this->buildWinstonDraftFixture();
        $draftMatchId = (int) $this->fetchGame($gameId)['draft_match_id'];
        $u1Username = $this->fetchUsername($u1);
        $u2Username = $this->fetchUsername($u2);

        $summaryFor = function (int $userId) use ($gameId): array {
            foreach ($this->games->listGamesForUser($userId) as $summary) {
                if ($summary['id'] === $gameId) {
                    return $summary;
                }
            }
            self::fail("game {$gameId} missing from listGamesForUser()");
        };

        $currentPlayerUserId = (int) $this->fetchWinstonState($draftMatchId)['current_player_user_id'];
        $expectedUsername = $currentPlayerUserId === $u1 ? $u1Username : $u2Username;

        $summary = $summaryFor($u1);
        self::assertNull($summary['current_turn_username']);
        self::assertSame([$expectedUsername], $summary['awaiting_response_usernames']);
        self::assertSame($currentPlayerUserId === $u1, $summaryFor($u1)['is_awaiting_your_response']);
        self::assertSame($currentPlayerUserId === $u2, $summaryFor($u2)['is_awaiting_your_response']);

        // Passing the (empty, freshly-dealt) pile 1 hands the turn to
        // pile 2 rather than the opponent -- current_player_user_id only
        // changes once a full turn (take, or decline all 3 piles) ends,
        // so this alone doesn't move who's awaited yet.
        $this->games->submitWinstonDraftPick($gameId, $currentPlayerUserId, 'pass');
        self::assertSame([$expectedUsername], $summaryFor($u1)['awaiting_response_usernames'], 'still the same player\'s turn -- only the current pile advanced');

        // Passing piles 2 and 3 too ends the turn (mandatory auto-draw),
        // handing it to the other player.
        $this->games->submitWinstonDraftPick($gameId, $currentPlayerUserId, 'pass');
        $this->games->submitWinstonDraftPick($gameId, $currentPlayerUserId, 'pass');

        $otherUserId = $currentPlayerUserId === $u1 ? $u2 : $u1;
        $otherUsername = $otherUserId === $u1 ? $u1Username : $u2Username;
        self::assertSame($otherUserId, (int) $this->fetchWinstonState($draftMatchId)['current_player_user_id']);
        self::assertSame([$otherUsername], $summaryFor($u1)['awaiting_response_usernames']);
    }

    public function testWinstonDraftStartGameRequiresBothDecksSubmitted(): void
    {
        $fixture = $this->buildWinstonDraftFixture();
        $gameId = $fixture['gameId'];
        $u1 = $fixture['u1'];
        $u2 = $fixture['u2'];

        $this->driveWinstonDraftToDeckBuilding($gameId, $u1, $u2);
        $match = $this->fetchDraftMatch((int) $this->fetchGame($gameId)['draft_match_id']);
        if ($match['status'] !== 'deck_building') {
            self::markTestSkipped('this particular shuffle produced an auto-loss -- see testWinstonDraftAutoLosesPlayerWithFewerThanTwelveDraftedCards()');
        }

        $this->expectException(GameStateException::class);
        $this->expectExceptionMessage('cannot start until both players have submitted their drafted deck');

        $this->submitFullWinstonDraftDeck($gameId, $u1);
        $this->games->startGame($gameId);
    }

    // -- Grid Draft (issue #188) ---------------------------------------------

    /** @return array{gameId:int, u1:int, u2:int} */
    private function buildGridDraftFixture(
        string $poolSource = 'random_48',
        ?string $customPoolText = null,
        int $winsNeeded = 1,
    ): array {
        $u1 = $this->insertUser('griddraft-' . uniqid('u1'));
        $u2 = $this->insertUser('griddraft-' . uniqid('u2'));

        $gameId = $this->games->createGame(
            $u1,
            [$u1, $u2],
            format: 'draft',
            winsNeeded: $winsNeeded,
            deckType: 'grid_draft',
            gridDraftPoolSource: $poolSource,
            gridDraftCustomPoolText: $customPoolText,
        );

        return ['gameId' => $gameId, 'u1' => $u1, 'u2' => $u2];
    }

    private function fetchGridState(int $draftMatchId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM draft_grid_state WHERE draft_match_id = :id');
        $stmt->execute(['id' => $draftMatchId]);

        return $stmt->fetch();
    }

    /**
     * Drives a full 6-round Grid Draft to completion for both $u1/$u2 via a
     * simple deterministic policy, exercised purely through the public API
     * (submitGridDraftPick()/getState()), the same surface the real
     * frontend uses: the round's first pick always takes row 0 (all 3
     * cells, since nothing's been taken from a freshly-dealt grid yet);
     * the second pick always takes column 0, which crosses row 0's own
     * first cell and so always yields exactly 2 cards -- a deterministic
     * exercise of the intersection-counting rule on every single round.
     * Capped well above the exact 12 picks (6 rounds x 2 picks) a full
     * draft actually needs, purely as a safety net against an
     * infinite-loop regression.
     */
    private function driveGridDraftToDeckBuilding(int $gameId, int $u1, int $u2): void
    {
        for ($i = 0; $i < 50; $i++) {
            $state = $this->games->getState($gameId, $u1);
            $gridDraft = $state['grid_draft'];
            if ($gridDraft['status'] !== 'drafting') {
                return;
            }

            $drafting = $gridDraft['drafting'];
            $currentUserId = $drafting['is_your_turn'] ? $u1 : $u2;
            [$axis, $index] = $drafting['first_pick'] === null ? ['row', 0] : ['column', 0];

            $this->games->submitGridDraftPick($gameId, $currentUserId, $axis, $index);
        }

        self::fail('Grid Draft did not complete within 50 picks -- possible infinite loop');
    }

    /** Submits all of $userId's own drafted cards as their deck (the max end of the open-ended range). */
    private function submitFullGridDraftDeck(int $gameId, int $userId): void
    {
        $state = $this->games->getState($gameId, $userId);
        $cardIds = array_column($state['grid_draft']['deck_building']['drafted_cards'], 'card_id');
        self::assertGreaterThanOrEqual(12, count($cardIds));

        $this->games->submitDraftDeck($gameId, $userId, $cardIds);
    }

    public function testCreateGameRejectsGridDraftForNonDraftFormat(): void
    {
        $creator = $this->insertUser('griddraft-nondraft-alice');
        $bob = $this->insertUser('griddraft-nondraft-bob');

        $this->expectException(GameStateException::class);
        $this->expectExceptionMessage('only supported for the "draft" format');

        $this->games->createGame($creator, [$creator, $bob], deckType: 'grid_draft', gridDraftPoolSource: 'random_48');
    }

    public function testCreateGameGridDraftPoolIsTruncatedToFiftyFour(): void
    {
        $fixture = $this->buildGridDraftFixture('one_of_each');

        $draftMatchId = (int) $this->fetchGame($fixture['gameId'])['draft_match_id'];
        $match = $this->fetchDraftMatch($draftMatchId);

        self::assertCount(54, json_decode((string) $match['pool_card_ids'], true), 'one_of_each\'s 133 cards are randomly narrowed down to 54 before the draft begins, same as Quick Draft\'s/Winston Draft\'s own pool caps');
    }

    public function testCreateGameGridDraftCustomPoolBelowMinimumIsRejected(): void
    {
        $creator = $this->insertUser('griddraft-undersized-alice');
        $bob = $this->insertUser('griddraft-undersized-bob');

        $this->expectException(GameStateException::class);
        $this->expectExceptionMessage('at least 54 are required');

        $this->games->createGame(
            $creator,
            [$creator, $bob],
            format: 'draft',
            deckType: 'grid_draft',
            gridDraftPoolSource: 'custom',
            gridDraftCustomPoolText: "20 Charity\n",
        );
    }

    public function testCreateGameGridDraftRejectsTheStructurePoolSourceAsUndersized(): void
    {
        $creator = $this->insertUser('griddraft-structure-alice');
        $bob = $this->insertUser('griddraft-structure-bob');

        // Unlike Quick Draft (which tops up a short pool mid-draft by
        // reshuffling discards) or Winston Draft (whose own 45-card
        // target matches the Structure deck's 45 cards exactly), Grid
        // Draft has no top-up mechanism at all -- the Structure deck's 45
        // cards fall short of the 54 Grid Draft always requires, so this
        // pool source must be rejected outright rather than silently
        // dealing a short final round.
        $this->expectException(GameStateException::class);
        $this->expectExceptionMessage('requires exactly 54');

        $this->games->createGame(
            $creator,
            [$creator, $bob],
            format: 'draft',
            deckType: 'grid_draft',
            gridDraftPoolSource: 'structure',
        );
    }

    public function testGridDraftDealsNineCardGridAndRandomlyPicksFirstPicker(): void
    {
        $fixture = $this->buildGridDraftFixture();
        $gameId = $fixture['gameId'];
        $u1 = $fixture['u1'];
        $u2 = $fixture['u2'];

        $draftMatchId = (int) $this->fetchGame($gameId)['draft_match_id'];
        $gridState = $this->fetchGridState($draftMatchId);

        self::assertCount(9, json_decode((string) $gridState['grid_card_ids'], true));
        self::assertCount(45, json_decode((string) $gridState['remaining_deck_card_ids'], true), '54-card pool minus the 9 cards dealt into round 1\'s grid');
        self::assertSame(1, (int) $gridState['current_round']);
        self::assertContains((int) $gridState['first_picker_user_id'], [$u1, $u2]);
        self::assertSame((int) $gridState['first_picker_user_id'], (int) $gridState['current_turn_user_id']);
        self::assertNull($gridState['first_pick_axis']);
        self::assertNull($gridState['first_pick_index']);

        $state = $this->games->getState($gameId, $u1);
        self::assertSame('drafting', $state['grid_draft']['status']);
        self::assertCount(9, $state['grid_draft']['drafting']['grid_cards']);
        self::assertSame(1, $state['grid_draft']['drafting']['current_round']);
        self::assertSame(6, $state['grid_draft']['drafting']['total_rounds']);
        self::assertSame(45, $state['grid_draft']['drafting']['remaining_deck_count']);
        self::assertNull($state['grid_draft']['drafting']['first_pick']);
    }

    public function testGridDraftRejectsAPickFromWhoeverIsNotTheCurrentTurn(): void
    {
        $fixture = $this->buildGridDraftFixture();
        $gameId = $fixture['gameId'];
        $u1 = $fixture['u1'];
        $u2 = $fixture['u2'];

        $draftMatchId = (int) $this->fetchGame($gameId)['draft_match_id'];
        $currentTurnUserId = (int) $this->fetchGridState($draftMatchId)['current_turn_user_id'];
        $otherUserId = $currentTurnUserId === $u1 ? $u2 : $u1;

        $this->expectException(GameStateException::class);
        $this->expectExceptionMessage("It's not your turn");

        $this->games->submitGridDraftPick($gameId, $otherUserId, 'row', 0);
    }

    public function testGridDraftRejectsAnInvalidAxis(): void
    {
        $fixture = $this->buildGridDraftFixture();
        $gameId = $fixture['gameId'];

        $draftMatchId = (int) $this->fetchGame($gameId)['draft_match_id'];
        $currentTurnUserId = (int) $this->fetchGridState($draftMatchId)['current_turn_user_id'];

        $this->expectException(GameStateException::class);
        $this->expectExceptionMessage('axis must be "row" or "column"');

        $this->games->submitGridDraftPick($gameId, $currentTurnUserId, 'diagonal', 0);
    }

    public function testGridDraftRejectsAnOutOfRangeIndex(): void
    {
        $fixture = $this->buildGridDraftFixture();
        $gameId = $fixture['gameId'];

        $draftMatchId = (int) $this->fetchGame($gameId)['draft_match_id'];
        $currentTurnUserId = (int) $this->fetchGridState($draftMatchId)['current_turn_user_id'];

        $this->expectException(GameStateException::class);
        $this->expectExceptionMessage('index must be between 0 and 2');

        $this->games->submitGridDraftPick($gameId, $currentTurnUserId, 'row', 3);
    }

    public function testGridDraftFirstPickTakesFullRowAndHandsTurnToOpponent(): void
    {
        $fixture = $this->buildGridDraftFixture();
        $gameId = $fixture['gameId'];
        $u1 = $fixture['u1'];
        $u2 = $fixture['u2'];

        $draftMatchId = (int) $this->fetchGame($gameId)['draft_match_id'];
        $firstPickerUserId = (int) $this->fetchGridState($draftMatchId)['current_turn_user_id'];
        $secondPickerUserId = $firstPickerUserId === $u1 ? $u2 : $u1;

        $result = $this->games->submitGridDraftPick($gameId, $firstPickerUserId, 'row', 0);

        self::assertCount(3, $result['cards_taken'], 'the round\'s first pick always yields a full 3 cards -- nothing has been taken from a freshly-dealt grid yet');
        self::assertFalse($result['round_completed']);
        self::assertTrue($result['turn_advanced']);
        self::assertFalse($result['draft_completed']);

        $drafted = json_decode((string) $this->fetchDraftMatchPlayer($draftMatchId, $firstPickerUserId)['drafted_card_ids'], true);
        self::assertCount(3, $drafted);

        $gridState = $this->fetchGridState($draftMatchId);
        self::assertSame($secondPickerUserId, (int) $gridState['current_turn_user_id']);
        self::assertSame('row', $gridState['first_pick_axis']);
        self::assertSame(0, (int) $gridState['first_pick_index']);

        $grid = json_decode((string) $gridState['grid_card_ids'], true);
        self::assertSame([null, null, null], [$grid[0], $grid[1], $grid[2]], 'row 0\'s own 3 cells are now empty');
        self::assertNotNull($grid[3], 'row 1 is completely untouched by a row pick');
        self::assertNotNull($grid[6], 'row 2 is completely untouched by a row pick');
    }

    public function testGridDraftDraftingStateExposesEachPlayersOwnDraftedCardsToTheOtherPlayer(): void
    {
        // Grid Draft's grid is dealt face-up and visible to both players the
        // whole time, unlike Winston Draft's face-down piles -- so, unlike
        // Winston/Quick Draft (where drafted_so_far is strictly the
        // viewer's own picks), Grid Draft's getState() also hands the
        // viewer their opponent's own accumulated picks.
        $fixture = $this->buildGridDraftFixture();
        $gameId = $fixture['gameId'];
        $u1 = $fixture['u1'];
        $u2 = $fixture['u2'];

        $draftMatchId = (int) $this->fetchGame($gameId)['draft_match_id'];
        $firstPickerUserId = (int) $this->fetchGridState($draftMatchId)['current_turn_user_id'];
        $secondPickerUserId = $firstPickerUserId === $u1 ? $u2 : $u1;

        $this->games->submitGridDraftPick($gameId, $firstPickerUserId, 'row', 0);

        $firstPickerDrafted = json_decode(
            (string) $this->fetchDraftMatchPlayer($draftMatchId, $firstPickerUserId)['drafted_card_ids'],
            true
        );

        $firstPickerState = $this->games->getState($gameId, $firstPickerUserId)['grid_draft']['drafting'];
        self::assertSame($firstPickerDrafted, array_map(fn ($card) => $card['card_id'], $firstPickerState['drafted_so_far']));
        self::assertSame([], $firstPickerState['opponent_drafted_so_far'], 'the opponent has not picked anything yet');

        $secondPickerState = $this->games->getState($gameId, $secondPickerUserId)['grid_draft']['drafting'];
        self::assertSame([], $secondPickerState['drafted_so_far'], 'the viewer has not picked anything yet');
        self::assertSame(
            $firstPickerDrafted,
            array_map(fn ($card) => $card['card_id'], $secondPickerState['opponent_drafted_so_far']),
            'the second picker can see the first picker\'s own drafted cards, since Grid Draft is open information'
        );
    }

    public function testGridDraftSecondPickCrossingTheFirstRowYieldsOnlyTwoCards(): void
    {
        $fixture = $this->buildGridDraftFixture();
        $gameId = $fixture['gameId'];
        $u1 = $fixture['u1'];
        $u2 = $fixture['u2'];

        $draftMatchId = (int) $this->fetchGame($gameId)['draft_match_id'];
        $firstPickerUserId = (int) $this->fetchGridState($draftMatchId)['current_turn_user_id'];
        $secondPickerUserId = $firstPickerUserId === $u1 ? $u2 : $u1;

        $this->games->submitGridDraftPick($gameId, $firstPickerUserId, 'row', 0);

        // Column 0 crosses row 0 at cell 0 (already taken), leaving only
        // cells 3 and 6 -- exactly 2 cards, derived purely by counting
        // still-non-null cells, never by comparing axis/index explicitly.
        $result = $this->games->submitGridDraftPick($gameId, $secondPickerUserId, 'column', 0);

        self::assertCount(2, $result['cards_taken']);
        self::assertTrue($result['round_completed']);
        self::assertTrue($result['turn_advanced']);
        self::assertFalse($result['draft_completed']);

        $drafted = json_decode((string) $this->fetchDraftMatchPlayer($draftMatchId, $secondPickerUserId)['drafted_card_ids'], true);
        self::assertCount(2, $drafted);
    }

    public function testGridDraftSecondPickNotCrossingTheFirstRowYieldsThreeCards(): void
    {
        $fixture = $this->buildGridDraftFixture();
        $gameId = $fixture['gameId'];
        $u1 = $fixture['u1'];
        $u2 = $fixture['u2'];

        $draftMatchId = (int) $this->fetchGame($gameId)['draft_match_id'];
        $firstPickerUserId = (int) $this->fetchGridState($draftMatchId)['current_turn_user_id'];
        $secondPickerUserId = $firstPickerUserId === $u1 ? $u2 : $u1;

        $this->games->submitGridDraftPick($gameId, $firstPickerUserId, 'row', 0);

        // Row 1 is a completely different row from row 0 -- untouched, so
        // it still yields all 3 of its own cards.
        $result = $this->games->submitGridDraftPick($gameId, $secondPickerUserId, 'row', 1);

        self::assertCount(3, $result['cards_taken']);
        self::assertTrue($result['round_completed']);
    }

    public function testGridDraftRejectsASecondPickOfTheExactSameLineAlreadyFullyTaken(): void
    {
        $fixture = $this->buildGridDraftFixture();
        $gameId = $fixture['gameId'];
        $u1 = $fixture['u1'];
        $u2 = $fixture['u2'];

        $draftMatchId = (int) $this->fetchGame($gameId)['draft_match_id'];
        $firstPickerUserId = (int) $this->fetchGridState($draftMatchId)['current_turn_user_id'];
        $secondPickerUserId = $firstPickerUserId === $u1 ? $u2 : $u1;

        $this->games->submitGridDraftPick($gameId, $firstPickerUserId, 'row', 0);

        $this->expectException(GameStateException::class);
        $this->expectExceptionMessage('No cards remain in that row/column');

        $this->games->submitGridDraftPick($gameId, $secondPickerUserId, 'row', 0);
    }

    public function testGridDraftAlternatesFirstPickerEachRoundAndDealsAFreshGrid(): void
    {
        $fixture = $this->buildGridDraftFixture();
        $gameId = $fixture['gameId'];
        $u1 = $fixture['u1'];
        $u2 = $fixture['u2'];

        $draftMatchId = (int) $this->fetchGame($gameId)['draft_match_id'];
        $round1FirstPickerUserId = (int) $this->fetchGridState($draftMatchId)['current_turn_user_id'];
        $round1SecondPickerUserId = $round1FirstPickerUserId === $u1 ? $u2 : $u1;

        $this->games->submitGridDraftPick($gameId, $round1FirstPickerUserId, 'row', 0);
        $this->games->submitGridDraftPick($gameId, $round1SecondPickerUserId, 'column', 0);

        $gridState = $this->fetchGridState($draftMatchId);
        self::assertSame(2, (int) $gridState['current_round']);
        self::assertSame($round1SecondPickerUserId, (int) $gridState['first_picker_user_id'], 'whoever picked second in round 1 picks first in round 2');
        self::assertSame($round1SecondPickerUserId, (int) $gridState['current_turn_user_id']);
        self::assertNull($gridState['first_pick_axis']);
        self::assertNull($gridState['first_pick_index']);
        self::assertCount(9, json_decode((string) $gridState['grid_card_ids'], true), 'round 2 deals a completely fresh 9-card grid');
        self::assertCount(36, json_decode((string) $gridState['remaining_deck_card_ids'], true), '54 - 9 (round 1) - 9 (round 2)');

        foreach (json_decode((string) $gridState['grid_card_ids'], true) as $cell) {
            self::assertNotNull($cell, 'a freshly-dealt round has nothing taken from it yet');
        }
    }

    public function testGridDraftProceedsToDeckBuildingAfterSixRoundsConservingFiftyFourMinusDiscards(): void
    {
        $fixture = $this->buildGridDraftFixture();
        $gameId = $fixture['gameId'];
        $u1 = $fixture['u1'];
        $u2 = $fixture['u2'];
        $draftMatchId = (int) $this->fetchGame($gameId)['draft_match_id'];

        $this->driveGridDraftToDeckBuilding($gameId, $u1, $u2);

        $match = $this->fetchDraftMatch($draftMatchId);
        self::assertSame('deck_building', $match['status'], 'Grid Draft always yields at least 12 cards per player (min 15 with this deterministic policy), so there\'s no auto-loss path the way Winston Draft has');

        $u1Cards = json_decode((string) $this->fetchDraftMatchPlayer($draftMatchId, $u1)['drafted_card_ids'], true);
        $u2Cards = json_decode((string) $this->fetchDraftMatchPlayer($draftMatchId, $u2)['drafted_card_ids'], true);

        // This deterministic policy (row 0 then column 0 every round)
        // always takes 3 + 2 = 5 of each round's 9 dealt cards, discarding
        // the other 4 -- 6 rounds x 5 kept = 30 total drafted, 6 x 4 = 24
        // discarded, 30 + 24 = 54.
        self::assertSame(30, count($u1Cards) + count($u2Cards));
        self::assertGreaterThanOrEqual(12, count($u1Cards));
        self::assertGreaterThanOrEqual(12, count($u2Cards));

        self::assertSame([], array_values(array_intersect($u1Cards, $u2Cards)), 'no catalog card id was drafted by both players -- random_48\'s own pool has no duplicate catalog ids to begin with');
    }

    public function testGridDraftMatchProgressesGamesAndCompletesAtTwoWins(): void
    {
        $fixture = $this->buildGridDraftFixture(winsNeeded: 1);
        $gameId = $fixture['gameId'];
        $u1 = $fixture['u1'];
        $u2 = $fixture['u2'];
        $draftMatchId = (int) $this->fetchGame($gameId)['draft_match_id'];

        $this->driveGridDraftToDeckBuilding($gameId, $u1, $u2);

        $this->submitFullGridDraftDeck($gameId, $u1);
        $this->submitFullGridDraftDeck($gameId, $u2);
        $this->games->startGame($gameId);

        $matchWins = [$u1 => 0, $u2 => 0];
        $currentGameId = $gameId;
        for ($gamesPlayed = 0; $gamesPlayed < 3; $gamesPlayed++) {
            $winnerUserId = $this->completeQuickDraftGameByPassing($currentGameId);
            $matchWins[$winnerUserId]++;

            if ($matchWins[$winnerUserId] >= 2) {
                $finalMatch = $this->fetchDraftMatch($draftMatchId);
                self::assertSame('completed', $finalMatch['status']);
                self::assertSame($winnerUserId, (int) $finalMatch['winner_user_id']);

                return;
            }

            $nextGameStmt = $this->pdo->prepare(
                "SELECT id FROM games WHERE draft_match_id = :match_id AND status = 'waiting' ORDER BY match_game_number DESC LIMIT 1"
            );
            $nextGameStmt->execute(['match_id' => $draftMatchId]);
            $nextGameId = (int) $nextGameStmt->fetchColumn();
            self::assertSame($fixture['gameId'] !== $nextGameId, true);

            $nextGameRow = $this->fetchGame($nextGameId);
            self::assertSame('grid_draft', $nextGameRow['deck_type']);

            $this->submitFullGridDraftDeck($nextGameId, $u1);
            $this->submitFullGridDraftDeck($nextGameId, $u2);
            $this->games->startGame($nextGameId);
            // Round 1 of game 2+ starts frozen until the previous game's
            // loser decides who goes first -- resolve to the default so
            // completeQuickDraftGameByPassing() can proceed.
            $loserUserId = $winnerUserId === $u1 ? $u2 : $u1;
            $this->games->setPlayFirstNextMatchGame($nextGameId, $loserUserId, false);
            $currentGameId = $nextGameId;
        }

        self::fail('a best-of-three match can never need a 4th game');
    }

    /**
     * Same single-active-player shape as Winston Draft's own equivalent
     * test above -- draftAwaitingResponseUsernames() names exactly
     * whoever draft_grid_state.current_turn_user_id currently is
     * (Grid Draft alternates first-pick/second-pick within a round, and
     * a first pick immediately hands the turn to the OTHER player for
     * the second pick -- unlike Winston Draft, this can move the
     * awaited player mid-round, not just at a round boundary).
     */
    public function testListGamesForUserShowsAwaitingResponseUsernameForGridDraftCurrentTurn(): void
    {
        ['gameId' => $gameId, 'u1' => $u1, 'u2' => $u2] = $this->buildGridDraftFixture();
        $draftMatchId = (int) $this->fetchGame($gameId)['draft_match_id'];
        $u1Username = $this->fetchUsername($u1);
        $u2Username = $this->fetchUsername($u2);

        $summaryFor = function (int $userId) use ($gameId): array {
            foreach ($this->games->listGamesForUser($userId) as $summary) {
                if ($summary['id'] === $gameId) {
                    return $summary;
                }
            }
            self::fail("game {$gameId} missing from listGamesForUser()");
        };

        $firstPickerUserId = (int) $this->fetchGridState($draftMatchId)['current_turn_user_id'];
        $firstPickerUsername = $firstPickerUserId === $u1 ? $u1Username : $u2Username;

        $summary = $summaryFor($u1);
        self::assertNull($summary['current_turn_username']);
        self::assertSame([$firstPickerUsername], $summary['awaiting_response_usernames']);

        $this->games->submitGridDraftPick($gameId, $firstPickerUserId, 'row', 0);

        $secondPickerUserId = $firstPickerUserId === $u1 ? $u2 : $u1;
        $secondPickerUsername = $secondPickerUserId === $u1 ? $u1Username : $u2Username;
        self::assertSame($secondPickerUserId, (int) $this->fetchGridState($draftMatchId)['current_turn_user_id']);
        self::assertSame([$secondPickerUsername], $summaryFor($u1)['awaiting_response_usernames'], 'the first pick immediately hands the turn to the other player');
    }

    public function testGridDraftStartGameRequiresBothDecksSubmitted(): void
    {
        $fixture = $this->buildGridDraftFixture();
        $gameId = $fixture['gameId'];
        $u1 = $fixture['u1'];
        $u2 = $fixture['u2'];

        $this->driveGridDraftToDeckBuilding($gameId, $u1, $u2);

        $this->expectException(GameStateException::class);
        $this->expectExceptionMessage('cannot start until both players have submitted their drafted deck');

        $this->submitFullGridDraftDeck($gameId, $u1);
        $this->games->startGame($gameId);
    }

    // -- Resigning (GameService::resignGame()) ------------------------------

    private function fetchRoundByNumber(int $gameId, int $roundNumber): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM game_rounds WHERE game_id = :game_id AND round_number = :round_number');
        $stmt->execute(['game_id' => $gameId, 'round_number' => $roundNumber]);

        return $stmt->fetch();
    }

    private function fetchGamePlayer(int $gamePlayerId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM game_players WHERE id = :id');
        $stmt->execute(['id' => $gamePlayerId]);

        return $stmt->fetch();
    }

    public function testResignInDuelFormatImmediatelyCompletesGameForTheOpponent(): void
    {
        $u1 = $this->insertUser('resign-duel-p1');
        $u2 = $this->insertUser('resign-duel-p2');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('duel', 'in_progress', :created_by, 2)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $p2 = $this->insertGamePlayer($gameId, $u2, 1);
        $roundId = $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $result = $this->games->resignGame($gameId, $p1);

        self::assertFalse($result['round_scored']);
        self::assertTrue($result['game_completed']);
        self::assertSame($p2, $result['winner_game_player_id']);

        $game = $this->fetchGame($gameId);
        self::assertSame('completed', $game['status']);
        self::assertSame($p2, (int) $game['winner_game_player_id']);
        self::assertNotNull($game['completed_at']);

        $round = $this->fetchRoundByNumber($gameId, 1);
        self::assertSame('abandoned', $round['status']);
        self::assertNull($round['current_turn_game_player_id']);

        self::assertNotNull($this->fetchGamePlayer($p1)['resigned_at']);

        // currentRound() -- the one thing playMood()/pass() gate on --
        // can no longer find an 'in_progress' round for this game, so
        // nothing can be played against it anymore.
        $this->expectException(GameStateException::class);
        $this->games->pass($gameId, $p2);
    }

    public function testResignInTeamFormatCreditsTheOpposingTeam(): void
    {
        ['gameId' => $gameId, 'p1' => $p1, 'p3' => $p3] = $this->buildTeamFixture();

        $result = $this->games->resignGame($gameId, $p1);

        self::assertTrue($result['game_completed']);
        self::assertSame($p3, $result['winner_game_player_id']);

        $game = $this->fetchGame($gameId);
        self::assertSame('completed', $game['status']);
        self::assertSame(1, (int) $game['winner_team_id']);
        // p3 is the lower-seat_order member of the winning team (team 1),
        // matching finishTeamScoringAndAdvance()'s own representative
        // convention -- see resignGame()'s docblock.
        self::assertSame($p3, (int) $game['winner_game_player_id']);
    }

    public function testResigningOnYourOwnTurnSkipsToTheNextActivePlayerInStandardFormat(): void
    {
        ['gameId' => $gameId, 'p1' => $p1, 'p2' => $p2, 'p3' => $p3, 'apathyId' => $apathyId] = $this->buildThreePlayerFixture();

        $this->games->playMood($gameId, $p1, $apathyId, []);
        $round = $this->fetchRound($gameId);
        self::assertSame($p2, (int) $round['current_turn_game_player_id']);

        $result = $this->games->resignGame($gameId, $p2);

        self::assertFalse($result['round_scored']);
        self::assertFalse($result['game_completed']);

        $round = $this->fetchRound($gameId);
        self::assertSame($p3, (int) $round['current_turn_game_player_id'], 'p2\'s own turn should be skipped straight to p3');

        self::assertNotNull($this->fetchGamePlayer($p2)['resigned_at']);
        self::assertSame('in_progress', $this->fetchGame($gameId)['status'], 'a 3-player standard game must not end just because one player resigned');

        // p2 no longer has a turn to pass on -- the round has already
        // moved on to p3.
        $this->expectException(GameStateException::class);
        $this->expectExceptionMessage("not player {$p2}'s turn");
        $this->games->pass($gameId, $p2);
    }

    /**
     * "All of that player's cards leave play" -- only the 3-4 player
     * 'standard' "continue without them" path needs this, since that's the
     * only resignation outcome where the board keeps being played on by
     * everyone else. Moods and hand cards both go to the bottom of the
     * deck, not the discard pile -- a resignation isn't a scoring event,
     * so it shouldn't feed discard-pile-driven effects (Altruism,
     * Corruption, etc.). See GameService::removeResignedPlayerCardsFromBoard().
     */
    public function testResigningMovesAllOfThatPlayersInPlayMoodsAndHandToTheBottomOfTheDeck(): void
    {
        ['gameId' => $gameId, 'p1' => $p1, 'p2' => $p2] = $this->buildThreePlayerFixture();

        $courageId = $this->insertGameCard($gameId, 7, 'in_play', $p2); // Courage
        $charityId = $this->insertGameCard($gameId, 3, 'in_play', $p2); // Charity
        $otherPlayersMoodId = $this->insertGameCard($gameId, 8, 'in_play', $p1); // Dignity -- untouched
        $p2HandCardId = $this->insertGameCard($gameId, 20, 'hand', $p2); // Pacifism -- p2's hand

        $this->games->resignGame($gameId, $p2);

        $stmt = $this->pdo->prepare(
            'SELECT id, zone, owner_game_player_id FROM game_cards WHERE id IN (:c1, :c2, :c3, :c4)'
        );
        $stmt->execute(['c1' => $courageId, 'c2' => $charityId, 'c3' => $otherPlayersMoodId, 'c4' => $p2HandCardId]);
        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $rows[(int) $row['id']] = $row;
        }

        self::assertSame('deck', $rows[$courageId]['zone']);
        self::assertNull($rows[$courageId]['owner_game_player_id'], 'standard format uses one shared deck, not a per-player one');
        self::assertSame('deck', $rows[$charityId]['zone']);
        self::assertSame('deck', $rows[$p2HandCardId]['zone'], "a resigned player's hand must also go to the bottom of the deck");
        self::assertSame('in_play', $rows[$otherPlayersMoodId]['zone'], 'another player\'s mood must be untouched by an opponent\'s resignation');
    }

    public function testResignedPlayerIsNeverCreditedARoundWinEvenWithTheHighestScore(): void
    {
        [
            'gameId' => $gameId, 'p1' => $p1, 'p2' => $p2, 'p3' => $p3,
            'apathyId' => $apathyId, 'complacencyId' => $complacencyId,
        ] = $this->buildThreePlayerFixture();

        $this->games->playMood($gameId, $p1, $apathyId, []); // p1 scores 4
        $this->games->playMood($gameId, $p2, $complacencyId, []); // p2 also scores 4 -- tied with p1

        $round = $this->fetchRound($gameId);
        self::assertSame($p3, (int) $round['current_turn_game_player_id']);

        // p2 resigns after already playing (not their turn, so this
        // doesn't touch turn state) -- 2 active players remain (p1, p3),
        // so the game keeps going rather than completing.
        $this->games->resignGame($gameId, $p2);

        $final = $this->games->pass($gameId, $p3); // p3 scores 0, ending round 1

        self::assertTrue($final['round_scored']);
        self::assertFalse($final['game_completed']); // wins_needed is 2 for buildThreePlayerFixture()

        $round1 = $this->fetchRoundByNumber($gameId, 1);
        self::assertSame('scored', $round1['status']);
        self::assertSame($p1, (int) $round1['winner_game_player_id'], 'p2 must never win despite tying the actual high score');
        self::assertSame($p1, (int) $round1['winner_game_player_id']);
    }

    public function testResignationCascadesToGameCompletionWhenOnlyOneActivePlayerRemains(): void
    {
        ['gameId' => $gameId, 'p1' => $p1, 'p2' => $p2, 'p3' => $p3] = $this->buildThreePlayerFixture();

        $first = $this->games->resignGame($gameId, $p2);
        self::assertFalse($first['game_completed'], 'still 2 active players (p1, p3) -- the game must keep going');

        $second = $this->games->resignGame($gameId, $p3);
        self::assertTrue($second['game_completed'], 'down to 1 active player -- the game must complete the same way a 2-player resign always has');
        self::assertSame($p1, $second['winner_game_player_id']);

        $game = $this->fetchGame($gameId);
        self::assertSame('completed', $game['status']);
        self::assertSame($p1, (int) $game['winner_game_player_id']);

        $round1 = $this->fetchRoundByNumber($gameId, 1);
        self::assertSame('abandoned', $round1['status']);
    }

    public function testResignInDraftFormatAdvancesTheMatchLikeAnyOtherGameWin(): void
    {
        ['gameId' => $gameId, 'u1' => $u1, 'u2' => $u2] = $this->buildQuickDraftFixture(winsNeeded: 1);
        $this->driveQuickDraftToDeckBuilding($gameId, $u1, $u2);
        $this->submitFullQuickDraftDeck($gameId, $u1);
        $this->submitFullQuickDraftDeck($gameId, $u2);
        $this->games->startGame($gameId);

        $p1 = $this->games->gamePlayerIdFor($gameId, $u1);
        $p2 = $this->games->gamePlayerIdFor($gameId, $u2);

        $result = $this->games->resignGame($gameId, $p1);

        self::assertTrue($result['game_completed']);
        self::assertSame($p2, $result['winner_game_player_id']);

        // Best-of-three needs 2 match wins -- a single resign-induced game
        // win only credits ONE, so the match itself isn't over yet, but
        // advanceDraftMatch() must still have run: the winner's match-win
        // count went up and a game 2 was created for the match to continue.
        $draftMatchId = (int) $this->fetchGame($gameId)['draft_match_id'];
        $match = $this->fetchDraftMatch($draftMatchId);
        self::assertSame('deck_building', $match['status']);
        self::assertSame(1, (int) $this->fetchDraftMatchPlayer($draftMatchId, $u2)['wins']);

        $nextGameStmt = $this->pdo->prepare(
            "SELECT id FROM games WHERE draft_match_id = :match_id AND match_game_number = 2"
        );
        $nextGameStmt->execute(['match_id' => $draftMatchId]);
        self::assertNotFalse($nextGameStmt->fetch(), 'a resign-induced win must still advance best-of-three match progression');
    }

    /**
     * End-to-end version of BoardStateTest/MoodPlayServiceTest's own
     * resigned-target coverage -- proves BoardStateRepository::load()
     * actually threads game_players.resigned_at into the real BoardState
     * a live playMood() call runs against, not just that BoardState's own
     * constructor honors the flag when a test builds one directly.
     */
    public function testPlayingACardCannotTargetAResignedPlayerEndToEnd(): void
    {
        ['gameId' => $gameId, 'p1' => $p1, 'p3' => $p3] = $this->buildThreePlayerFixture();

        $this->games->resignGame($gameId, $p3);

        $honorId = $this->insertGameCard($gameId, 15, 'hand', $p1); // Honor, value 3

        $this->expectException(InvalidChoiceException::class);
        $this->expectExceptionMessage('is not a valid player');
        $this->games->playMood($gameId, $p1, $honorId, ['target_player_id' => $p3]);
    }

    public function testResignFailsWhenTheGameIsNotInProgress(): void
    {
        $u1 = $this->insertUser('resign-notstarted-p1');
        $u2 = $this->insertUser('resign-notstarted-p2');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('duel', 'waiting', :created_by, 2)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();
        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $this->insertGamePlayer($gameId, $u2, 1);

        $this->expectException(GameStateException::class);
        $this->expectExceptionMessage('not in progress');
        $this->games->resignGame($gameId, $p1);
    }

    public function testResignFailsForAPlayerNotSeatedInTheGame(): void
    {
        ['gameId' => $gameId] = $this->buildThreePlayerFixture();

        $this->expectException(GameStateException::class);
        $this->expectExceptionMessage('not seated');
        $this->games->resignGame($gameId, 999999);
    }

    public function testResignFailsIfThePlayerHasAlreadyResigned(): void
    {
        ['gameId' => $gameId, 'p2' => $p2] = $this->buildThreePlayerFixture();

        $this->games->resignGame($gameId, $p2);

        $this->expectException(GameStateException::class);
        $this->expectExceptionMessage('already resigned');
        $this->games->resignGame($gameId, $p2);
    }

    public function testResignFailsWhileADecisionIsPending(): void
    {
        ['gameId' => $gameId, 'p1' => $p1, 'p2' => $p2, 'apathyId' => $apathyId] = $this->buildThreePlayerFixture();

        $round = $this->fetchRound($gameId);
        $this->pdo->prepare(
            'INSERT INTO game_pending_decision_batches (game_id, game_round_id, played_card_id, initiating_game_player_id, top_level_choices, invocation_choices)
             VALUES (:game_id, :round_id, :card_id, :initiator, \'{}\', \'{}\')'
        )->execute(['game_id' => $gameId, 'round_id' => (int) $round['id'], 'card_id' => $apathyId, 'initiator' => $p1]);

        $this->expectException(GameStateException::class);
        $this->expectExceptionMessage('decision still pending');
        $this->games->resignGame($gameId, $p2);
    }

    /**
     * Lifetime game/match stats (issue #106) -- recordGameCompletionStats()/
     * recordMatchCompletionStats() are called from every code path that
     * completes a game/match, so these exercise each of those paths
     * rather than the stats-writing logic itself in isolation.
     */
    public function testStandardGameCompletionRecordsLifetimeGameWinAndLoss(): void
    {
        $u1 = $this->insertUser('statsgame-p1');
        $u2 = $this->insertUser('statsgame-p2');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed)
             VALUES ('standard', 'in_progress', :created_by, 1)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $p2 = $this->insertGamePlayer($gameId, $u2, 1);
        $apathyId = $this->insertGameCard($gameId, 55, 'hand', $p1); // Apathy, value 4
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $this->games->playMood($gameId, $p1, $apathyId, []);
        $this->games->pass($gameId, $p2);

        self::assertSame(
            ['game_wins' => 1, 'game_losses' => 0, 'game_win_percentage' => 100, 'match_wins' => 0, 'match_losses' => 0, 'match_win_percentage' => null],
            $this->games->lifetimeStatsFor($u1)
        );
        self::assertSame(
            ['game_wins' => 0, 'game_losses' => 1, 'game_win_percentage' => 0, 'match_wins' => 0, 'match_losses' => 0, 'match_win_percentage' => null],
            $this->games->lifetimeStatsFor($u2)
        );
    }

    /** A team win credits BOTH teammates' lifetime game_wins, not just the one representative game_player_id games.winner_game_player_id itself points at. */
    public function testTeamGameCompletionCreditsBothTeammatesLifetimeGameWins(): void
    {
        $u1 = $this->insertUser('statsteam-p1');
        $u2 = $this->insertUser('statsteam-p2');
        $u3 = $this->insertUser('statsteam-p3');
        $u4 = $this->insertUser('statsteam-p4');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('team', 'in_progress', :created_by, 1)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertTeamGamePlayer($gameId, $u1, 0, 0);
        $p2 = $this->insertTeamGamePlayer($gameId, $u2, 1, 0);
        $p3 = $this->insertTeamGamePlayer($gameId, $u3, 2, 1);
        $p4 = $this->insertTeamGamePlayer($gameId, $u4, 3, 1);

        $complacencyId = $this->insertGameCard($gameId, 5, 'hand', $p1); // white, value 4
        $this->insertGameCard($gameId, 44, 'hand', $p2);
        $this->insertGameCard($gameId, 55, 'hand', $p3);
        $this->insertGameCard($gameId, 83, 'hand', $p4);

        $roundId = $this->insertTeamGameRound($gameId, 1, $p1);
        $this->pdo->prepare(
            'UPDATE game_rounds SET current_turn_game_player_id = :p1, plays_remaining = 1, team_turn_1_game_player_id = :p1, team_turn_2_game_player_id = :p3 WHERE id = :round_id'
        )->execute(['p1' => $p1, 'p3' => $p3, 'round_id' => $roundId]);

        // Team 0 (p1 + p2) scores 4 to team 1's 0 -- an outright win that,
        // with wins_needed = 1, also ends the game.
        $this->games->playMood($gameId, $p1, $complacencyId, []);
        $this->games->pass($gameId, $p3);
        $this->games->pass($gameId, $p2);
        $this->games->pass($gameId, $p4);

        self::assertSame(1, $this->games->lifetimeStatsFor($u1)['game_wins']);
        self::assertSame(1, $this->games->lifetimeStatsFor($u2)['game_wins']);
        self::assertSame(1, $this->games->lifetimeStatsFor($u3)['game_losses']);
        self::assertSame(1, $this->games->lifetimeStatsFor($u4)['game_losses']);
    }

    public function testResignationCompletionRecordsLifetimeGameWinAndLoss(): void
    {
        $u1 = $this->insertUser('statsresign-p1');
        $u2 = $this->insertUser('statsresign-p2');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('duel', 'in_progress', :created_by, 2)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $p2 = $this->insertGamePlayer($gameId, $u2, 1);
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $this->games->resignGame($gameId, $p1);

        self::assertSame(1, $this->games->lifetimeStatsFor($u1)['game_losses']);
        self::assertSame(1, $this->games->lifetimeStatsFor($u2)['game_wins']);
    }

    /**
     * A completed best-of-three draft match records BOTH game-level stats
     * (once per game) AND match-level stats (once, when the match itself
     * finishes) -- these are independent counters, not derived from one
     * another.
     */
    public function testDraftMatchCompletionRecordsLifetimeMatchWinAndLossOnTopOfPerGameStats(): void
    {
        ['gameId' => $gameId, 'u1' => $u1, 'u2' => $u2] = $this->buildQuickDraftFixture(winsNeeded: 1);
        $this->driveQuickDraftToDeckBuilding($gameId, $u1, $u2);
        $this->submitFullQuickDraftDeck($gameId, $u1);
        $this->submitFullQuickDraftDeck($gameId, $u2);
        $this->games->startGame($gameId);
        $winnerUserId = $this->completeQuickDraftGameByPassing($gameId);
        $loserUserId = $winnerUserId === $u1 ? $u2 : $u1;

        // Game 1 alone must not yet count as a match win/loss -- the match
        // itself isn't decided until a second game is won.
        self::assertSame(1, $this->games->lifetimeStatsFor($winnerUserId)['game_wins']);
        self::assertSame(0, $this->games->lifetimeStatsFor($winnerUserId)['match_wins']);

        $draftMatchId = (int) $this->fetchGame($gameId)['draft_match_id'];
        $nextGameStmt = $this->pdo->prepare(
            "SELECT id FROM games WHERE draft_match_id = :match_id AND status = 'waiting' ORDER BY match_game_number DESC LIMIT 1"
        );
        $nextGameStmt->execute(['match_id' => $draftMatchId]);
        $nextGameId = (int) $nextGameStmt->fetchColumn();

        $this->submitFullQuickDraftDeck($nextGameId, $u1);
        $this->submitFullQuickDraftDeck($nextGameId, $u2);
        $this->games->startGame($nextGameId);
        $this->games->setPlayFirstNextMatchGame($nextGameId, $loserUserId, false);
        $this->completeQuickDraftGameByPassing($nextGameId);

        $winnerStats = $this->games->lifetimeStatsFor($winnerUserId);
        $loserStats = $this->games->lifetimeStatsFor($loserUserId);
        self::assertSame(2, $winnerStats['game_wins'], 'both games of the match were won by the same player');
        self::assertSame(1, $winnerStats['match_wins']);
        self::assertSame(2, $loserStats['game_losses']);
        self::assertSame(1, $loserStats['match_losses']);
    }

    /** Winston Draft's under-12-cards auto-loss completes the MATCH without game 1 ever completing -- see finalizeWinstonDraft(). Must still count as a match win/loss even though there's no game_wins/game_losses to go with it. */
    public function testWinstonDraftAutoLossRecordsLifetimeMatchStatsButNotGameStats(): void
    {
        $fixture = $this->buildWinstonDraftFixture();
        $gameId = $fixture['gameId'];
        $u1 = $fixture['u1'];
        $u2 = $fixture['u2'];
        $draftMatchId = (int) $this->fetchGame($gameId)['draft_match_id'];

        // Same deterministic setup as testWinstonDraftAutoLosesPlayerWithFewerThanTwelveDraftedCards()
        // -- seed a lopsided split (u1 short of WINSTON_MIN_DECK_SIZE) and
        // force the next pick to empty the deck/piles simultaneously,
        // triggering finalizeWinstonDraft()'s auto-loss branch.
        $this->pdo->prepare('UPDATE draft_match_players SET drafted_card_ids = :ids WHERE draft_match_id = :match_id AND user_id = :user_id')
            ->execute(['ids' => json_encode(range(1, 5)), 'match_id' => $draftMatchId, 'user_id' => $u1]);
        $this->pdo->prepare('UPDATE draft_match_players SET drafted_card_ids = :ids WHERE draft_match_id = :match_id AND user_id = :user_id')
            ->execute(['ids' => json_encode(range(6, 45)), 'match_id' => $draftMatchId, 'user_id' => $u2]);

        $currentPlayerUserId = (int) $this->fetchWinstonState($draftMatchId)['current_player_user_id'];
        $this->pdo->prepare(
            'UPDATE draft_winston_state
             SET remaining_deck_card_ids = :deck, pile_1_card_ids = :pile1, pile_2_card_ids = :pile2, pile_3_card_ids = :pile3, current_pile_number = 1
             WHERE draft_match_id = :match_id'
        )->execute([
            'deck' => json_encode([]),
            'pile1' => json_encode([50]),
            'pile2' => json_encode([]),
            'pile3' => json_encode([]),
            'match_id' => $draftMatchId,
        ]);

        $result = $this->games->submitWinstonDraftPick($gameId, $currentPlayerUserId, 'take');
        self::assertTrue($result['draft_completed']);

        $match = $this->fetchDraftMatch($draftMatchId);
        self::assertSame('completed', $match['status']);
        $winnerUserId = (int) $match['winner_user_id'];
        self::assertSame($u2, $winnerUserId);

        self::assertSame(0, $this->games->lifetimeStatsFor($winnerUserId)['game_wins'], 'game 1 never completed, only the match did');
        self::assertSame(1, $this->games->lifetimeStatsFor($winnerUserId)['match_wins']);
        self::assertSame(0, $this->games->lifetimeStatsFor($u1)['game_losses']);
        self::assertSame(1, $this->games->lifetimeStatsFor($u1)['match_losses']);
    }

    public function testLifetimeStatsForReturnsAllZerosForAUserWithNoHistory(): void
    {
        $u1 = $this->insertUser('statsfresh');

        self::assertSame(
            [
                'game_wins' => 0,
                'game_losses' => 0,
                'game_win_percentage' => null,
                'match_wins' => 0,
                'match_losses' => 0,
                'match_win_percentage' => null,
            ],
            $this->games->lifetimeStatsFor($u1)
        );
    }
}
