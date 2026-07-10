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
    ): void {
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
        // to -- see testCreateGameDealsAStandardDeckByDefault() for the
        // 'standard' deck_type's own (smaller, rarity-weighted) math.
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

    public function testCreateGameDealsAStandardDeckByDefault(): void
    {
        // 'standard' -- 23 common/14 uncommon/6 rare/2 mythic (45 total),
        // matching a new physical box's own printed rarity distribution --
        // is deck_type's default, unlike the full 133-card 'one_of_each'
        // pool every game used before this existed (see
        // testCreateGameAndStartGameDealsCardsAndBeginsFirstRound() above,
        // which pins deckType explicitly for that reason).
        $creator = $this->insertUser('deck-alice');
        $bob = $this->insertUser('deck-bob');

        $gameId = $this->games->createGame($creator, [$creator, $bob]);
        self::assertSame('standard', $this->fetchGame($gameId)['deck_type']);

        $this->games->startGame($gameId);

        $cardIdsStmt = $this->pdo->prepare("SELECT card_id FROM game_cards WHERE game_id = :game_id");
        $cardIdsStmt->execute(['game_id' => $gameId]);
        $cardIds = array_map(intval(...), $cardIdsStmt->fetchAll(PDO::FETCH_COLUMN));

        self::assertCount(45, $cardIds);
        self::assertCount(45, array_unique($cardIds), 'a standard deck must be singleton -- no repeated card ids');

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

        // Exactly one of the two players is on turn; the flag should
        // disagree between their two lists.
        self::assertNotSame($creatorGames[0]['is_your_turn'], $bobGames[0]['is_your_turn']);
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

        $this->insertGameCard($gameId, 8, 'hand', $p1); // Dignity: base 3, dice/alt 5
        $this->insertGameCard($gameId, 3, 'hand', $p1); // Charity: base 1, no dice value
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $hand = $this->games->getState($gameId, $u1)['you']['hand'];
        $byCardId = [];
        foreach ($hand as $card) {
            $byCardId[$card['card_id']] = $card;
        }

        self::assertSame(3, $byCardId[8]['base_value']);
        self::assertSame(5, $byCardId[8]['alt_value']);
        self::assertTrue($byCardId[8]['has_dice_value']);
        self::assertSame(3, $byCardId[8]['value']); // not in play yet -- value equals base_value

        self::assertSame(1, $byCardId[3]['base_value']);
        self::assertNull($byCardId[3]['alt_value']);
        self::assertFalse($byCardId[3]['has_dice_value']);
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
        $this->insertGameCard($gameId, 7, 'hand', $p1); // Courage, white
        $this->insertGameCard($gameId, 28, 'hand', $p1); // Anxiety, blue
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $hand = $this->games->getState($gameId, $u1)['you']['hand'];
        $byCardId = [];
        foreach ($hand as $card) {
            $byCardId[$card['card_id']] = $card;
        }

        $courageReaction = self::findFieldByKey($byCardId[7]['choice_fields'], 'scorn_suppress_target');
        self::assertNotNull($courageReaction, 'expected a Scorn reaction field on the white Courage card');
        self::assertSame(['white'], $courageReaction['filter']['colors']);

        $anxietyReaction = self::findFieldByKey($byCardId[28]['choice_fields'], 'scorn_suppress_target');
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
        $this->insertGameCard($gameId, 3, 'hand', $p1); // Charity, base value 1 -- qualifies
        $this->insertGameCard($gameId, 8, 'hand', $p1); // Dignity, base value 3 -- doesn't qualify
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $hand = $this->games->getState($gameId, $u1)['you']['hand'];
        $byCardId = [];
        foreach ($hand as $card) {
            $byCardId[$card['card_id']] = $card;
        }

        self::assertNotNull(self::findFieldByKey($byCardId[3]['choice_fields'], 'validation_extra_play'));
        self::assertNull(self::findFieldByKey($byCardId[8]['choice_fields'], 'validation_extra_play'));
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
        $this->insertGameCard($gameId, 8, 'hand', $p1); // Dignity -- has its own afterPlaying choice
        $this->insertGameCard($gameId, 3, 'hand', $p1); // Charity, value 1 -- discard fodder
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $playResult = $this->games->playMood($gameId, $p1, 8, ['discard_card_id' => 3]);
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
        $this->insertGameCard($gameId, 40, 'hand', $p1); // Guile -- discard cost + afterPlaying target
        $this->insertGameCard($gameId, 3, 'hand', $p1);
        $this->insertGameCard($gameId, 7, 'hand', $p1); // Guile's own 2-card discard cost
        $this->insertGameCard($gameId, 9, 'in_play', $p2); // Guile's own afterPlaying target
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $playResult = $this->games->playMood($gameId, $p1, 40, ['discard_card_ids' => [3, 7], 'target_mood_id' => 9]);
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

        $this->insertGameCard($gameId, 8, 'hand', $p1); // Dignity
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $hand = $this->games->getState($gameId, $u1)['you']['hand'];

        self::assertNull(self::findByCardId($hand, 8)['copy_simulation']);
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
        $this->insertGameCard($gameId, 32, 'hand', $p1); // Creativity
        $this->insertGameCard($gameId, 8, 'in_play', $p2); // Dignity -- has its own afterPlaying
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $hand = $this->games->getState($gameId, $u1)['you']['hand'];
        $creativity = self::findByCardId($hand, 32);

        self::assertNull(self::findFieldByKey($creativity['copy_simulation'][8]['extra_fields'], 'duplicity_repeat'));
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
        $this->insertGameCard($gameId, 32, 'hand', $p1); // Creativity
        $this->insertGameCard($gameId, 8, 'in_play', $p2); // Dignity, white
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $hand = $this->games->getState($gameId, $u1)['you']['hand'];
        $creativity = self::findByCardId($hand, 32);

        $scornField = self::findFieldByKey($creativity['copy_simulation'][8]['extra_fields'], 'scorn_suppress_target');
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
        $this->insertGameCard($gameId, 32, 'hand', $p1); // Creativity
        $this->insertGameCard($gameId, 40, 'in_play', $p2); // Guile, base value 0 -- qualifies
        $this->insertGameCard($gameId, 8, 'in_play', $p2); // Dignity, base value 3 -- doesn't qualify
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $hand = $this->games->getState($gameId, $u1)['you']['hand'];
        $creativity = self::findByCardId($hand, 32);

        self::assertNotNull(self::findFieldByKey($creativity['copy_simulation'][40]['extra_fields'], 'validation_extra_play'));
        self::assertNull(self::findFieldByKey($creativity['copy_simulation'][8]['extra_fields'], 'validation_extra_play'));
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

        $this->insertGameCard($gameId, 32, 'hand', $p1); // Creativity, alone in hand
        $this->insertGameCard($gameId, 40, 'in_play', $p2); // Guile -- needs 2 *other* hand cards to discard
        $this->insertGameCard($gameId, 8, 'in_play', $p2); // Dignity -- no "to play" cost at all
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $creativity = self::findByCardId($this->games->getState($gameId, $u1)['you']['hand'], 32);
        self::assertFalse($creativity['copy_simulation'][40]['cost_payable']);
        self::assertTrue($creativity['copy_simulation'][8]['cost_payable']);

        $this->insertGameCard($gameId, 3, 'hand', $p1); // Charity
        $this->insertGameCard($gameId, 4, 'hand', $p1); // Chivalry -- now 2 other hand cards exist

        $creativity = self::findByCardId($this->games->getState($gameId, $u1)['you']['hand'], 32);
        self::assertTrue($creativity['copy_simulation'][40]['cost_payable']);
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
        $this->insertGameCard($gameId, 32, 'hand', $p1); // Creativity
        $this->insertGameCard($gameId, 3, 'hand', $p1); // Charity, value 1 -- discarded by the first invocation
        $this->insertGameCard($gameId, 4, 'hand', $p1); // Chivalry, value 3 -- discarded by the repeat
        $this->insertGameCard($gameId, 8, 'in_play', $p2); // Dignity -- the copy target

        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $playResult = $this->games->playMood($gameId, $p1, 32, [
            'copy_card_id' => 8,
            'discard_card_id' => 3,
        ]);
        self::assertTrue($playResult['pending_decision'] ?? false);

        $respondResult = $this->games->respondToDecision($gameId, $p1, [
            'duplicity_repeat' => ['repeat' => true, 'choices' => ['discard_card_id' => 4]],
        ]);
        self::assertArrayNotHasKey('pending_decision', $respondResult);

        $registry = DefaultEffectRegistry::build();
        $state = (new BoardStateRepository($registry))->load($gameId);

        self::assertSame([], $state->hand($p1));
        self::assertSame([3, 4], $state->discardPile());
        self::assertTrue($state->isInPlay(32));
        self::assertSame('white', $state->colorOf(32)); // Dignity's color, confirming the copy took effect
        self::assertSame(5, $state->valueOf(32));
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

        $this->insertGameCard($gameId, 37, 'in_play', $p1); // real Duplicity
        $this->insertGameCard($gameId, 32, 'in_play', $p1); // Creativity, already copying Duplicity
        $this->pdo->prepare('UPDATE game_cards SET copied_card_id = 37 WHERE game_id = :game_id AND card_id = 32')
            ->execute(['game_id' => $gameId]);
        $this->insertGameCard($gameId, 8, 'hand', $p1); // Dignity
        $this->insertGameCard($gameId, 3, 'hand', $p1); // Charity, value 1
        $this->insertGameCard($gameId, 4, 'hand', $p1); // Chivalry, value 3
        $this->insertGameCard($gameId, 6, 'hand', $p1); // Conviction, value 2
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $playResult = $this->games->playMood($gameId, $p1, 8, ['discard_card_id' => 3]);
        self::assertTrue($playResult['pending_decision'] ?? false);

        $respondResult1 = $this->games->respondToDecision($gameId, $p1, [
            'duplicity_repeat' => ['repeat' => true, 'choices' => ['discard_card_id' => 4]],
        ]);
        self::assertTrue($respondResult1['pending_decision'] ?? false, 'a second independent Duplicity source should still be available');

        $respondResult2 = $this->games->respondToDecision($gameId, $p1, [
            'duplicity_repeat' => ['repeat' => true, 'choices' => ['discard_card_id' => 6]],
        ]);
        self::assertArrayNotHasKey('pending_decision', $respondResult2);

        $registry = DefaultEffectRegistry::build();
        $state = (new BoardStateRepository($registry))->load($gameId);

        self::assertSame([], $state->hand($p1));
        self::assertSame([3, 4, 6], $state->discardPile());
        self::assertSame(5, $state->valueOf(8));
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

        $this->insertGameCard($gameId, 3, 'hand', $p1); // Charity -- the one card the grant below covers
        $this->insertGameCard($gameId, 7, 'hand', $p1); // Courage -- not covered
        $roundId = $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        // Mirrors the grant IntimidationEffect hands out, restricted to one
        // specific revealed card -- see BoardState::grantAllows()'s
        // 'specific_card_ids' case.
        $this->pdo->prepare('UPDATE game_rounds SET pending_play_grants = :grants WHERE id = :id')
            ->execute(['grants' => json_encode([['type' => 'specific_card_ids', 'values' => [3]]]), 'id' => $roundId]);

        $hand = $this->games->getState($gameId, $u1)['you']['hand'];

        self::assertTrue(self::findByCardId($hand, 3)['is_playable']);
        self::assertFalse(self::findByCardId($hand, 7)['is_playable']);
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

        $this->insertGameCard($gameId, 40, 'hand', $p1); // Guile, alone -- needs 2 *other* hand cards to discard
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $hand = $this->games->getState($gameId, $u1)['you']['hand'];

        self::assertFalse(self::findByCardId($hand, 40)['is_playable']);
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

        $this->insertGameCard($gameId, 3, 'hand', $p1); // Charity -- otherwise unconditionally playable
        $this->insertGameRound($gameId, 1, $p2, $p2, 1); // p2's turn, not p1's

        $hand = $this->games->getState($gameId, $u1)['you']['hand'];

        self::assertFalse(self::findByCardId($hand, 3)['is_playable']);
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

        $this->insertGameCard($gameId, 12, 'in_play', $p1); // Faith
        $this->insertGameCard($gameId, 7, 'in_play', $p1); // Courage -- suppressed by Faith, for as long as Faith is in play
        $this->insertGameCard($gameId, 8, 'in_play', $p1); // Dignity -- suppressed until the end of the round, no tracked source (mirrors Repentance)
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $faithGameCardId = (int) $this->pdo->query(
            "SELECT id FROM game_cards WHERE game_id = {$gameId} AND card_id = 12"
        )->fetchColumn();

        $this->pdo->prepare(
            "UPDATE game_cards SET is_suppressed = 1, suppression_expiry = 'while_source_in_play', suppression_source_game_card_id = :source
             WHERE game_id = :game_id AND card_id = 7"
        )->execute(['source' => $faithGameCardId, 'game_id' => $gameId]);

        $this->pdo->prepare(
            "UPDATE game_cards SET is_suppressed = 1, suppression_expiry = 'end_of_round'
             WHERE game_id = :game_id AND card_id = 8"
        )->execute(['game_id' => $gameId]);

        $inPlay = $this->games->getState($gameId, $u1)['in_play'];

        $courage = self::findByCardId($inPlay, 7);
        self::assertTrue($courage['is_suppressed']);
        self::assertSame('while_source_in_play', $courage['suppression_expiry']);
        self::assertSame(12, $courage['suppressed_by_card_id']);
        self::assertSame('Faith', $courage['suppressed_by_name']);

        $dignity = self::findByCardId($inPlay, 8);
        self::assertTrue($dignity['is_suppressed']);
        self::assertSame('end_of_round', $dignity['suppression_expiry']);
        self::assertNull($dignity['suppressed_by_card_id']);
        self::assertNull($dignity['suppressed_by_name']);

        $faith = self::findByCardId($inPlay, 12);
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

        $this->insertGameCard($gameId, 11, 'in_play', $p1); // Encouragement
        $this->insertGameCard($gameId, 8, 'in_play', $p1); // Dignity (base 3, dice 5) -- boosted by Encouragement
        $this->insertGameCard($gameId, 12, 'in_play', $p1); // Faith
        $this->insertGameCard($gameId, 7, 'in_play', $p1); // Courage -- suppressed by Faith
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $this->pdo->prepare(
            "UPDATE game_cards SET effect_state = '{\"boostedMoodId\":8}' WHERE game_id = :game_id AND card_id = 11"
        )->execute(['game_id' => $gameId]);

        $faithGameCardId = (int) $this->pdo->query(
            "SELECT id FROM game_cards WHERE game_id = {$gameId} AND card_id = 12"
        )->fetchColumn();
        $this->pdo->prepare(
            "UPDATE game_cards SET is_suppressed = 1, suppression_expiry = 'while_source_in_play', suppression_source_game_card_id = :source
             WHERE game_id = :game_id AND card_id = 7"
        )->execute(['source' => $faithGameCardId, 'game_id' => $gameId]);

        $inPlay = $this->games->getState($gameId, $u1)['in_play'];

        $dignity = self::findByCardId($inPlay, 8);
        self::assertSame(5, $dignity['value']); // max(base 3, dice 5)
        self::assertSame(11, $dignity['boosted_by_card_id']);
        self::assertSame('Encouragement', $dignity['boosted_by_name']);

        $encouragement = self::findByCardId($inPlay, 11);
        self::assertNull($encouragement['boosted_by_card_id']);
        self::assertSame([['card_id' => 8, 'name' => 'Dignity', 'relationship' => 'dice_value']], $encouragement['affecting']);

        $faith = self::findByCardId($inPlay, 12);
        self::assertSame([['card_id' => 7, 'name' => 'Courage', 'relationship' => 'suppressed']], $faith['affecting']);

        $courage = self::findByCardId($inPlay, 7);
        self::assertSame([], $courage['affecting']);
        self::assertNull($courage['boosted_by_card_id']); // has no printed dice value at all
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

        $this->insertGameCard($gameId, 71, 'hand', $p1); // Paranoia
        $this->insertGameCard($gameId, 9, 'hand', $p2); // Discipline -- p2's only card, so the reveal is deterministic
        $this->insertGameCard($gameId, 106, 'deck', null, 0); // for Paranoia's own draw
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $this->games->playMood($gameId, $p1, 71, ['target_player_id' => $p2]);

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

        $this->insertGameCard($gameId, 11, 'hand', $p1); // Encouragement
        $this->insertGameCard($gameId, 8, 'in_play', $p1); // Dignity (base 3, dice 5) -- the mood targeted
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $this->games->playMood($gameId, $p1, 11, ['target_mood_id' => 8]);

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

        $this->insertGameCard($gameId, 3, 'hand', $p1); // Charity
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $this->games->playMood($gameId, $p1, 3, []);

        $playGrants = $this->games->getState($gameId, $u1)['round']['play_grants'];
        self::assertCount(1, $playGrants); // the base turn's own grant was just consumed playing Charity
        self::assertSame('An extra play from Charity', $playGrants[0]['description']);
        self::assertSame(3, $playGrants[0]['source_card_id']);
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

        $this->insertGameCard($gameId, 42, 'hand', $p1); // Imagination (blue)
        $this->insertGameCard($gameId, 3, 'in_play', $p1); // Charity (white)
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $this->games->playMood($gameId, $p1, 42, ['color' => 'red']);

        $inPlay = $this->games->getState($gameId, $u1)['in_play'];

        $imagination = self::findByCardId($inPlay, 42);
        self::assertSame('red', $imagination['color']);
        self::assertSame('blue', $imagination['base_color']);

        $charity = self::findByCardId($inPlay, 3);
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

        $this->insertGameCard($gameId, 78, 'hand', $p1); // Suspicion
        $this->insertGameCard($gameId, 9, 'hand', $p2);
        $this->insertGameCard($gameId, 3, 'hand', $p3);
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $this->games->playMood($gameId, $p1, 78, ['player_ids' => [$p2, $p3]]);

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
        ['gameId' => $gameId, 'p1' => $p1, 'p2' => $p2, 'p3' => $p3, 'u1' => $u1] = $this->buildThreePlayerFixture();

        $this->games->playMood($gameId, $p1, 55, []); // Apathy, value 4
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

        $this->insertGameCard($gameId, 84, 'hand', $p1); // Bravado
        $this->insertGameCard($gameId, 3, 'in_play', $p1); // Charity -- discarded as Bravado's own cost
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $this->games->playMood($gameId, $p1, 84, ['discard_mood_id' => 3]);

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

        $this->insertGameCard($gameId, 106, 'hand', $p1); // Zeal
        $this->insertGameCard($gameId, 2, 'hand', $p1); // Benevolence -- bottomed as Zeal's own cost
        $this->insertGameCard($gameId, 9, 'deck', null, 0); // Discipline -- what actually gets drawn
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $this->games->playMood($gameId, $p1, 106, ['hand_card_id' => 2]);

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

        $this->insertGameCard($gameId, 3, 'hand', $p1); // Charity
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $this->games->playMood($gameId, $p1, 3, []);

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

        $this->insertGameCard($gameId, 3, 'hand', $p1); // Charity -- grants the extra play
        $this->insertGameCard($gameId, 55, 'hand', $p1); // Apathy -- played using it
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $this->games->playMood($gameId, $p1, 3, []);
        $this->games->playMood($gameId, $p1, 55, []);

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

        $this->insertGameCard($gameId, 55, 'hand', $p1); // Apathy, value 4
        $this->insertGameCard($gameId, 83, 'hand', $p1); // Boredom, value 4 -- p1's round-2 card
        $this->insertGameCard($gameId, 5, 'hand', $p2); // Complacency, value 4 -- never played
        $this->insertGameCard($gameId, 27, 'deck', null, 0);
        $this->insertGameCard($gameId, 54, 'deck', null, 1);

        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        return ['gameId' => $gameId, 'p1' => $p1, 'p2' => $p2, 'p3' => $p3, 'u1' => $u1, 'u2' => $u2, 'u3' => $u3];
    }

    public function testPlayMoodAdvancesTurnWithoutEndingRoundWhenPlaysRemain(): void
    {
        ['gameId' => $gameId, 'p1' => $p1, 'p2' => $p2] = $this->buildThreePlayerFixture();

        $result = $this->games->playMood($gameId, $p1, 55, []);

        self::assertFalse($result['round_scored']);
        self::assertFalse($result['game_completed']);

        $round = $this->fetchRound($gameId);
        self::assertSame($p2, (int) $round['current_turn_game_player_id']);
        self::assertSame(1, (int) $round['plays_remaining']);
    }

    public function testFullRoundCycleAssignsHurtFeelingsDrawsForLosersAndCompletesGame(): void
    {
        ['gameId' => $gameId, 'p1' => $p1, 'p2' => $p2, 'p3' => $p3] = $this->buildThreePlayerFixture();

        // Round 1: p1 plays a mood worth 4, p2 and p3 both pass (score 0).
        $this->games->playMood($gameId, $p1, 55, []);
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
        $this->games->playMood($gameId, $p1, 83, []);

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

    public function testPassOutOfTurnIsRejected(): void
    {
        ['gameId' => $gameId, 'p2' => $p2] = $this->buildThreePlayerFixture();

        $this->expectException(GameStateException::class);
        $this->games->pass($gameId, $p2);
    }

    public function testPlayingOutOfTurnRaisesRulesLevelException(): void
    {
        ['gameId' => $gameId, 'p2' => $p2] = $this->buildThreePlayerFixture();

        $this->expectException(IllegalPlayException::class);
        $this->games->playMood($gameId, $p2, 5, []);
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

        $this->insertGameCard($gameId, 2, 'hand', $p1); // Benevolence, white
        $this->insertGameCard($gameId, 8, 'hand', $p1); // Dignity, white -- shares Benevolence's color
        $this->insertGameCard($gameId, 106, 'hand', $p1); // Zeal, red -- doesn't share
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        return ['gameId' => $gameId, 'p1' => $p1];
    }

    public function testRestrictedExtraPlayGrantIsEnforcedAfterReloadingFromTheDatabase(): void
    {
        ['gameId' => $gameId, 'p1' => $p1] = $this->buildBenevolenceFixture();

        $this->games->playMood($gameId, $p1, 2, []); // Benevolence -- grants a restricted extra play

        $round = $this->fetchRound($gameId);
        self::assertSame(1, (int) $round['plays_remaining']);
        self::assertNotNull($round['pending_play_grants']);

        // A fresh load() from the database (this is a brand new call, not
        // reusing any in-memory state from the play above) must still
        // reject Dignity as sharing Benevolence's color.
        $this->expectException(IllegalPlayException::class);
        $this->games->playMood($gameId, $p1, 8, []);
    }

    public function testRestrictedExtraPlayGrantAllowsAQualifyingCardAfterReload(): void
    {
        ['gameId' => $gameId, 'p1' => $p1] = $this->buildBenevolenceFixture();

        $this->games->playMood($gameId, $p1, 2, []); // Benevolence -- grants a restricted extra play
        $this->games->playMood($gameId, $p1, 106, []); // Zeal, red -- doesn't share Benevolence's color

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

        $this->insertGameCard($gameId, 4, 'hand', $p2); // Chivalry
        // p1 went first; it's now p2's turn -- p2 is a middle turn (not
        // the round's last), so the round stays in progress and
        // first_game_player_id isn't disturbed by this play.
        $this->insertGameRound($gameId, 1, $p1, $p2, 1);

        $this->games->playMood($gameId, $p2, 4, []);

        $registry = DefaultEffectRegistry::build();
        $state = (new BoardStateRepository($registry))->load($gameId);
        self::assertSame(5, $state->valueOf(4)); // p2 didn't go first this round
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

        $this->insertGameCard($gameId, 15, 'hand', $p1); // Honor, value 3
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        // p1 plays Honor naming p3, then wins round 1 outright (3 vs 0/0).
        $this->games->playMood($gameId, $p1, 15, ['target_player_id' => $p3]);
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

        $this->insertGameCard($gameId, 51, 'hand', $p1); // Sneakiness, value 5
        $this->insertGameCard($gameId, 120, 'in_play', $p2); // Generosity, value 6 -- already ahead
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        // Before the swap, p2 leads 6 to 5; after it, p1 should win 6 to 5.
        $this->games->playMood($gameId, $p1, 51, ['opponent_player_id' => $p2]);
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

        $this->insertGameCard($gameId, 107, 'hand', $p1); // Awe
        $this->insertGameCard($gameId, 3, 'deck', null, 0); // would be drawn if scoring weren't skipped
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $this->games->playMood($gameId, $p1, 107, ['target_player_id' => $p2]);
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

        $this->insertGameCard($gameId, 30, 'hand', $p1); // Bashfulness, value 6
        $this->insertGameCard($gameId, 3, 'deck', null, 0); // p2's loser draw
        $this->insertGameCard($gameId, 7, 'deck', null, 1); // p1's replacement draw
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $this->games->playMood($gameId, $p1, 30, []);
        $result = $this->games->pass($gameId, $p2);

        self::assertTrue($result['round_scored']);

        $registry = DefaultEffectRegistry::build();
        $state = (new BoardStateRepository($registry))->load($gameId);

        self::assertFalse($state->isInPlay(30));
        self::assertContains(7, $state->hand($p1));
        self::assertContains(3, $state->hand($p2));
        self::assertSame([30], $state->deck());
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

        $this->insertGameCard($gameId, 30, 'hand', $p1); // Bashfulness, value 6
        $this->insertGameCard($gameId, 3, 'deck', null, 0); // p2's loser draw
        $this->insertGameCard($gameId, 7, 'deck', null, 1); // p1's replacement draw (Courage) -- must stay unnamed
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $this->games->playMood($gameId, $p1, 30, []);
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

        $this->insertGameCard($gameId, 60, 'hand', $p1); // Corruption, value 2
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $this->games->playMood($gameId, $p1, 60, ['mode' => 'double_win']);
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

        $this->insertGameCard($gameId, 36, 'hand', $p1); // Doubt
        $this->insertGameCard($gameId, 8, 'hand', $p1); // Dignity, white -- revealed
        $this->insertGameCard($gameId, 7, 'hand', $p2); // Courage, white -- would be banned next round
        $this->insertGameCard($gameId, 55, 'deck', null, 0); // p1's replacement draw
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $this->games->playMood($gameId, $p1, 36, ['reveal_card_ids' => [8]]);
        $this->games->pass($gameId, $p2);

        $round2 = $this->fetchRound($gameId);
        self::assertSame(2, (int) $round2['round_number']);
        self::assertSame($p1, (int) $round2['current_turn_game_player_id']); // p1 won round 1

        $this->games->pass($gameId, $p1); // advance to p2's turn without ending round 2

        $this->expectException(IllegalPlayException::class);
        $this->games->playMood($gameId, $p2, 7, []);
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

        $this->insertGameCard($gameId, 124, 'hand', $p1); // Hope, value 0
        $this->insertGameCard($gameId, 55, 'hand', $p1); // Apathy, value 4 -- played with Hope's same-turn bonus
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $this->games->playMood($gameId, $p1, 124, []);
        $this->games->playMood($gameId, $p1, 55, []);
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

        $this->insertGameCard($gameId, 102, 'hand', $p1); // Stubbornness, value 3
        $this->insertGameCard($gameId, 66, 'in_play', $p2); // Hate, value 0
        $this->insertGameCard($gameId, 105, 'in_play', $p2); // Wrath, value 0 -- p2 now has 2 moods
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $this->games->playMood($gameId, $p1, 102, []);
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

        $this->insertGameCard($gameId, 102, 'hand', $p1); // Stubbornness, value 3
        $this->insertGameCard($gameId, 66, 'in_play', $p1); // Hate, value 0 -- p1 already has 1 mood before playing Stubbornness
        $this->insertGameCard($gameId, 105, 'in_play', $p2); // Wrath, value 0 -- p2 has exactly as many moods as p1 will, not more
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $this->games->playMood($gameId, $p1, 102, []); // p1 now has 2 moods (Hate + Stubbornness) vs p2's 1
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

        $this->insertGameCard($gameId, 120, 'hand', $p1); // Generosity
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $this->games->playMood($gameId, $p1, 120, ['target_player_id' => $p2]);

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

        $this->insertGameCard($gameId, 125, 'hand', $p1); // Joy, value 3
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $this->games->playMood($gameId, $p1, 125, []);
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

        $this->insertGameCard($gameId, 82, 'hand', $p1); // Arrogance
        $this->insertGameCard($gameId, 8, 'in_play', $p2); // Dignity, white
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $playResult = $this->games->playMood($gameId, $p1, 82, ['opponent_player_id' => $p2]);
        self::assertTrue($playResult['pending_decision'] ?? false);
        $this->games->respondToDecision($gameId, $p2, ['chosen_mood_id' => 8]);

        $registry = DefaultEffectRegistry::build();
        $repository = new BoardStateRepository($registry);
        $state = $repository->load($gameId);
        self::assertSame($p1, $state->ownerOf(8));

        $state->moveInPlayToDiscard(82);
        $repository->save($gameId, $state);

        $reloaded = $repository->load($gameId);
        self::assertSame($p2, $reloaded->ownerOf(8));
        self::assertFalse($reloaded->isInPlay(82));
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

        $this->insertGameCard($gameId, 3, 'hand', $p1); // Charity -- grants the extra play needed to also play Scorn this turn
        $this->insertGameCard($gameId, 24, 'hand', $p1); // Scorn, value 2, white
        $this->insertGameCard($gameId, 7, 'hand', $p1); // Courage, white -- played next round to trigger the reaction
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $this->games->playMood($gameId, $p1, 3, []);
        $this->games->playMood($gameId, $p1, 24, ['target_mood_id' => 3]); // Scorn suppresses Charity
        $this->games->pass($gameId, $p2);

        $round2 = $this->fetchRound($gameId);
        self::assertSame(2, (int) $round2['round_number']);
        self::assertSame($p1, (int) $round2['first_game_player_id']); // p1 won round 1 (2 to 0)

        $this->games->playMood($gameId, $p1, 7, ['scorn_suppress_target' => 24]); // Courage (white) reacts, suppressing Scorn itself

        $registry = DefaultEffectRegistry::build();
        $state = (new BoardStateRepository($registry))->load($gameId);
        self::assertTrue($state->isSuppressed(24));
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

        $this->insertGameCard($gameId, 86, 'hand', $p1); // Compulsion
        $this->insertGameCard($gameId, 3, 'hand', $p2);
        $this->insertGameCard($gameId, 7, 'hand', $p2);
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $playResult = $this->games->playMood($gameId, $p1, 86, ['target_player_id' => $p2]);
        self::assertTrue($playResult['pending_decision'] ?? false);

        $registry = DefaultEffectRegistry::build();
        $state = (new BoardStateRepository($registry))->load($gameId);
        self::assertTrue($state->isInPlay(86)); // cost/grant already resolved -- only the target's answer is outstanding
        self::assertSame([3, 7], $state->hand($p2));

        // The whole round is frozen -- not even the acting player can pass.
        try {
            $this->games->pass($gameId, $p1);
            self::fail('Expected passing while a decision is pending to be rejected');
        } catch (GameStateException) {
            // expected
        }

        $respondResult = $this->games->respondToDecision($gameId, $p2, ['given_card_id' => 3]);
        self::assertArrayNotHasKey('pending_decision', $respondResult);

        $state = (new BoardStateRepository($registry))->load($gameId);
        $p1Hand = $state->hand($p1);
        $p2Hand = $state->hand($p2);
        self::assertSame([3], $p1Hand);
        self::assertSame([7], $p2Hand);
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

        $this->insertGameCard($gameId, 86, 'hand', $p1); // Compulsion
        $this->insertGameCard($gameId, 3, 'hand', $p2);
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $beforePlay = $this->games->getState($gameId, $u1)['round']['pending_decision'];
        self::assertNull($beforePlay);

        $this->games->playMood($gameId, $p1, 86, ['target_player_id' => $p2]);

        $targetView = $this->games->getState($gameId, $u2)['round']['pending_decision'];
        self::assertSame($p1, $targetView['initiating_game_player_id']);
        self::assertSame(86, $targetView['played_card_id']);
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

        $this->insertGameCard($gameId, 67, 'hand', $p1); // Intimidation
        $this->insertGameCard($gameId, 5, 'hand', $p1); // Complacency -- not the revealed card
        $this->insertGameCard($gameId, 3, 'hand', $p2); // p2's only card -- guaranteed to be the one revealed
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $playResult = $this->games->playMood($gameId, $p1, 67, ['target_player_id' => $p2]);
        self::assertTrue($playResult['pending_decision'] ?? false);
        $this->games->respondToDecision($gameId, $p2, ['revealed_card_id' => 3]);

        $this->expectException(IllegalPlayException::class);
        $this->games->playMood($gameId, $p1, 5, []);
    }

    public function testInstabilityPausesForP2sOwnChoiceAndOnlyCompletesAfterTheyRespond(): void
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

        $this->insertGameCard($gameId, 96, 'hand', $p1); // Instability
        $this->insertGameCard($gameId, 9, 'in_play', $p1); // given in exchange
        $this->insertGameCard($gameId, 3, 'in_play', $p2); // candidate
        $this->insertGameCard($gameId, 7, 'in_play', $p2); // candidate
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $playResult = $this->games->playMood($gameId, $p1, 96, [
            'candidate_mood_ids' => [3, 7],
            'given_mood_id' => 9,
        ]);
        self::assertTrue($playResult['pending_decision'] ?? false);

        $registry = DefaultEffectRegistry::build();
        $state = (new BoardStateRepository($registry))->load($gameId);
        self::assertSame($p2, $state->ownerOf(3)); // not taken yet
        self::assertSame($p2, $state->ownerOf(7));

        $respondResult = $this->games->respondToDecision($gameId, $p2, ['taken_mood_id' => 7]);
        self::assertArrayNotHasKey('pending_decision', $respondResult);

        $state = (new BoardStateRepository($registry))->load($gameId);
        self::assertSame($p1, $state->ownerOf(7));
        self::assertSame($p2, $state->ownerOf(3)); // the other candidate is untouched
        self::assertSame($p2, $state->ownerOf(9));
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

        $this->insertGameCard($gameId, 78, 'hand', $p1); // Suspicion
        $this->insertGameCard($gameId, 9, 'hand', $p2);
        $this->insertGameCard($gameId, 3, 'hand', $p2);
        $this->insertGameCard($gameId, 106, 'hand', $p3);
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $playResult = $this->games->playMood($gameId, $p1, 78, ['player_ids' => [$p2, $p3]]);
        self::assertTrue($playResult['pending_decision'] ?? false);

        // p3 hasn't answered yet -- responding out of turn is rejected.
        try {
            $this->games->respondToDecision($gameId, $p3, ['discarded_card_id_' . $p3 => 106]);
            self::fail('Expected p3 to have no decision pending until p2 answers first');
        } catch (GameStateException) {
            // expected
        }

        $firstRespondResult = $this->games->respondToDecision($gameId, $p2, ['discarded_card_id_' . $p2 => 9]);
        self::assertTrue($firstRespondResult['pending_decision'] ?? false);

        // The whole batch resolves together only once its last row is
        // answered -- p2's discard hasn't actually happened yet either.
        $registry = DefaultEffectRegistry::build();
        $state = (new BoardStateRepository($registry))->load($gameId);
        self::assertSame([3, 9], $state->hand($p2));
        self::assertSame([106], $state->hand($p3));

        $finalRespondResult = $this->games->respondToDecision($gameId, $p3, ['discarded_card_id_' . $p3 => 106]);
        self::assertArrayNotHasKey('pending_decision', $finalRespondResult);

        $state = (new BoardStateRepository($registry))->load($gameId);
        self::assertSame([3], $state->hand($p2));
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

        $this->insertGameCard($gameId, 10, 'hand', $p1); // Disillusionment
        $this->insertGameCard($gameId, 9, 'in_play', $p2); // Discipline, white
        $this->insertGameCard($gameId, 53, 'in_play', $p2); // Ambition, black
        $this->insertGameCard($gameId, 28, 'in_play', $p3); // Anxiety, blue
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $playResult = $this->games->playMood($gameId, $p1, 10, []);
        self::assertTrue($playResult['pending_decision'] ?? false);

        $result1 = $this->games->respondToDecision($gameId, $p2, ['chosen_color_' . $p2 => 'black']);
        self::assertTrue($result1['pending_decision'] ?? false);

        $result2 = $this->games->respondToDecision($gameId, $p3, ['chosen_color_' . $p3 => 'blue']);
        self::assertTrue($result2['pending_decision'] ?? false);

        $registry = DefaultEffectRegistry::build();
        $state = (new BoardStateRepository($registry))->load($gameId);
        self::assertTrue($state->isInPlay(9)); // nothing discarded until the queue's last player answers
        self::assertTrue($state->isInPlay(53));
        self::assertTrue($state->isInPlay(28));

        $result3 = $this->games->respondToDecision($gameId, $p1, ['chosen_color_' . $p1 => 'black']);
        self::assertArrayNotHasKey('pending_decision', $result3);

        $state = (new BoardStateRepository($registry))->load($gameId);
        self::assertTrue($state->isInPlay(10));
        self::assertTrue($state->isInPlay(9)); // white, not chosen by anyone
        self::assertFalse($state->isInPlay(53)); // black
        self::assertFalse($state->isInPlay(28)); // blue
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

        $this->insertGameCard($gameId, 68, 'hand', $p1); // Malice
        $this->insertGameCard($gameId, 9, 'in_play', $p2); // Discipline, white
        $this->insertGameCard($gameId, 3, 'in_play', $p2); // Charity, white
        $this->insertGameCard($gameId, 8, 'in_play', $p3); // Dignity, white
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $playResult = $this->games->playMood($gameId, $p1, 68, ['target_player_id' => $p2]);
        self::assertTrue($playResult['pending_decision'] ?? false);

        $registry = DefaultEffectRegistry::build();
        $state = (new BoardStateRepository($registry))->load($gameId);
        self::assertTrue($state->isInPlay(9)); // not discarded yet
        self::assertTrue($state->isInPlay(3));

        $respondResult = $this->games->respondToDecision($gameId, $p2, ['chosen_mood_ids' => [9, 3]]);
        self::assertArrayNotHasKey('pending_decision', $respondResult);

        $state = (new BoardStateRepository($registry))->load($gameId);
        self::assertFalse($state->isInPlay(9));
        self::assertFalse($state->isInPlay(3));
        self::assertFalse($state->isInPlay(8)); // shares white with the two chosen moods
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

        $this->insertGameCard($gameId, 68, 'hand', $p1); // Malice
        $this->insertGameCard($gameId, 9, 'in_play', $p2); // Discipline, white -- chosen
        $this->insertGameCard($gameId, 3, 'in_play', $p2); // Charity, white -- chosen
        $this->insertGameCard($gameId, 8, 'in_play', $p3); // Dignity, white -- cascade, never chosen
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $this->games->playMood($gameId, $p1, 68, ['target_player_id' => $p2]);
        $this->games->respondToDecision($gameId, $p2, ['chosen_mood_ids' => [9, 3]]);

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

        $this->insertGameCard($gameId, 61, 'hand', $p1); // Cruelty
        $this->insertGameCard($gameId, 9, 'in_play', $p2); // Discipline
        $this->insertGameCard($gameId, 3, 'in_play', $p2); // Charity
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $this->games->playMood($gameId, $p1, 61, ['opponent_player_ids' => [$p2]]);

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

        $this->insertGameCard($gameId, 123, 'hand', $p1); // Harmony
        $this->insertGameCard($gameId, 3, 'discard', null); // Charity, already in the discard pile
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $this->games->playMood($gameId, $p1, 123, []);
        $this->games->playMood($gameId, $p1, 3, []);

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

        $this->insertGameCard($gameId, 40, 'hand', $p1); // Guile
        $this->insertGameCard($gameId, 9, 'hand', $p1); // discarded as Guile's cost
        $this->insertGameCard($gameId, 8, 'hand', $p1); // discarded as Guile's cost
        $this->insertGameCard($gameId, 3, 'in_play', $p2); // Charity -- taken
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $this->games->playMood($gameId, $p1, 40, ['discard_card_ids' => [9, 8], 'target_mood_id' => 3]);

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

        $this->insertGameCard($gameId, 82, 'hand', $p1); // Arrogance
        $this->insertGameCard($gameId, 3, 'in_play', $p2); // Charity, white -- qualifies
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $playResult = $this->games->playMood($gameId, $p1, 82, ['opponent_player_id' => $p2]);
        self::assertTrue($playResult['pending_decision'] ?? false);

        $this->games->respondToDecision($gameId, $p2, ['chosen_mood_id' => 3]);

        $inPlay = $this->games->getState($gameId, $u1)['in_play'];
        $charity = self::findByCardId($inPlay, 3);
        self::assertSame(
            [
                'original_owner_game_player_id' => $p2,
                'original_owner_name' => 'tempown2',
                'source_card_id' => 82,
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
        $state->moveInPlayToDiscard(82);
        $repository->save($gameId, $state);

        $inPlay = $this->games->getState($gameId, $u1)['in_play'];
        $charity = self::findByCardId($inPlay, 3);
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

        $this->insertGameCard($gameId, 56, 'hand', $p1); // Betrayal
        $this->insertGameCard($gameId, 3, 'in_play', $p1); // Charity -- given away
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        // Which mood to give away is a pending decision the acting player
        // (not an opponent) answers immediately after Betrayal enters play
        // -- see BetrayalEffect's own docblock.
        $playResult = $this->games->playMood($gameId, $p1, 56, ['recipient_player_id' => $p2]);
        self::assertTrue($playResult['pending_decision'] ?? false);

        $this->games->respondToDecision($gameId, $p1, ['target_mood_id' => 3]);

        $inPlay = $this->games->getState($gameId, $u1)['in_play'];
        $charity = self::findByCardId($inPlay, 3);
        self::assertSame(
            [
                'original_owner_game_player_id' => $p1,
                'original_owner_name' => 'tempown3',
                'source_card_id' => 56,
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

        $this->insertGameCard($gameId, 56, 'hand', $p1); // Betrayal -- p1's only mood
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $playResult = $this->games->playMood($gameId, $p1, 56, ['recipient_player_id' => $p2]);
        self::assertTrue($playResult['pending_decision'] ?? false);

        $pendingDecision = $this->games->getState($gameId, $u1)['round']['pending_decision'];
        self::assertSame('betrayal_give_mood', $pendingDecision['decision_type']);
        self::assertSame($p1, $pendingDecision['target_game_player_id']);
        self::assertTrue($pendingDecision['is_you']);

        $this->games->respondToDecision($gameId, $p1, ['target_mood_id' => 56]);

        $inPlay = $this->games->getState($gameId, $u1)['in_play'];
        $betrayal = self::findByCardId($inPlay, 56);
        self::assertSame($p2, $betrayal['owner_game_player_id']);
        self::assertSame(
            [
                'original_owner_game_player_id' => $p1,
                'original_owner_name' => 'selfbetray1',
                'source_card_id' => 56,
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

        $this->insertGameCard($gameId, 56, 'hand', $p1); // Betrayal
        $this->insertGameCard($gameId, 3, 'in_play', $p1); // Charity -- given away
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $this->games->playMood($gameId, $p1, 56, ['recipient_player_id' => $p2]);
        $this->games->respondToDecision($gameId, $p1, ['target_mood_id' => 3]);

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

        $this->insertGameCard($gameId, 84, 'hand', $p1); // Bravado
        $this->insertGameCard($gameId, 3, 'in_play', $p1); // Charity, value 1 -- discarded as cost
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $before = self::findByGamePlayerId($this->games->getState($gameId, $u1)['players'], $p1);
        self::assertSame(1, $before['total_score']); // just Charity

        $this->games->playMood($gameId, $p1, 84, ['discard_mood_id' => 3]);

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
        ['gameId' => $gameId, 'p1' => $p1, 'p2' => $p2, 'p3' => $p3, 'u1' => $u1] = $this->buildThreePlayerFixture();

        $this->games->playMood($gameId, $p1, 55, []); // Apathy, value 4 -- no ability
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

        $this->insertGameCard($gameId, 89, 'hand', $p1); // Exhilaration
        $this->insertGameCard($gameId, 55, 'in_play', $p1); // Apathy, value 4 -- sacrificed for the cost
        $this->insertGameCard($gameId, 106, 'in_play', $p1); // Zeal, value 3 -- survives, doubled
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $this->games->playMood($gameId, $p1, 89, ['discard_mood_id' => 55]);
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

        $this->insertGameCard($gameId, 85, 'hand', $p1); // Chaos
        $this->insertGameCard($gameId, 3, 'in_play', $p2); // Charity
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $this->games->playMood($gameId, $p1, 85, []);

        $registry = DefaultEffectRegistry::build();
        $state = (new BoardStateRepository($registry))->load($gameId);

        self::assertTrue($state->isInPlay(85));
        self::assertTrue($state->isInPlay(3));
        foreach ([$state->ownerOf(85), $state->ownerOf(3)] as $owner) {
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

        $this->insertGameCard($gameId, 132, 'hand', $p1); // Vulnerability, base 1 / dice 7
        $this->insertGameCard($gameId, 8, 'hand', $p2); // Dignity
        $this->insertGameCard($gameId, 3, 'hand', $p2); // Charity, value 1 -- discarded to pay Dignity's cost
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $this->games->playMood($gameId, $p1, 132, []);

        $registry = DefaultEffectRegistry::build();
        $repository = new BoardStateRepository($registry);
        self::assertSame(1, $repository->load($gameId)->valueOf(132)); // nothing discarded yet

        $this->games->playMood($gameId, $p2, 8, ['discard_card_id' => 3]);

        // p2's discard, from a separate request, has to survive the reload
        // for p1's Vulnerability to reflect it -- the round isn't over yet
        // (p3 hasn't gone), so this can be observed directly.
        self::assertSame(7, $repository->load($gameId)->valueOf(132));

        $result = $this->games->pass($gameId, $p3);
        self::assertTrue($result['round_scored']);

        $round2 = $this->fetchRound($gameId);
        self::assertSame(2, (int) $round2['round_number']);
        self::assertSame(0, (int) $round2['discarded_this_round']);
        self::assertSame(1, $repository->load($gameId)->valueOf(132)); // reset for the new round
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

        $this->insertGameCard($gameId, 11, 'hand', $p1); // Encouragement
        $this->insertGameCard($gameId, 9, 'in_play', $p1); // Discipline, base 6 / dice 3
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $this->games->playMood($gameId, $p1, 11, ['target_mood_id' => 9]);

        $registry = DefaultEffectRegistry::build();
        $state = (new BoardStateRepository($registry))->load($gameId);

        self::assertSame(6, $state->valueOf(9)); // higher of base(6)/dice(3)
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

        $insertOpenBatch = function () use ($gameId, $roundId, $p1): void {
            $stmt = $this->pdo->prepare(
                'INSERT INTO game_pending_decision_batches
                    (game_id, game_round_id, played_card_id, invocation_seq, initiating_game_player_id, top_level_choices, invocation_choices)
                 VALUES (:game_id, :round_id, 1, 0, :initiator, \'{}\', \'{}\')'
            );
            $stmt->execute(['game_id' => $gameId, 'round_id' => $roundId, 'initiator' => $p1]);
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

        $this->insertGameCard($gameId, 116, 'in_play', $p1); // Enthusiasm, value 0
        $this->insertGameCard($gameId, 8, 'in_play', $p1); // Dignity, base value 3
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $passResult1 = $this->games->pass($gameId, $p1);
        self::assertArrayNotHasKey('pending_decision', $passResult1); // just advances the turn to p2

        $passResult2 = $this->games->pass($gameId, $p2);
        self::assertTrue($passResult2['pending_decision'] ?? false, 'ending the round should pause for Enthusiasm\'s own decision');

        $state = $this->games->getState($gameId, $u1);
        self::assertTrue($state['round']['pending_decision']['is_you']);
        self::assertSame('enthusiasm_extra_score', $state['round']['pending_decision']['decision_type']);
        self::assertSame(116, $state['round']['pending_decision']['played_card_id']);
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
