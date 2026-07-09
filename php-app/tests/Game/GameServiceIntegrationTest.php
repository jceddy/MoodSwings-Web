<?php

declare(strict_types=1);

namespace MoodSwings\Tests\Game;

use MoodSwings\Game\BoardStateRepository;
use MoodSwings\Game\Exceptions\GameStateException;
use MoodSwings\Game\GameService;
use MoodSwings\Rules\DefaultEffectRegistry;
use MoodSwings\Rules\Exceptions\IllegalPlayException;
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

        $gameId = $this->games->createGame($creator, [$creator, $bob, $carol]);
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

        $gameId = $this->games->createGame($creator, [$creator, $bob]);
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

    public function testGetStateAppendsDuplicityRepeatFieldsForAHandCardWithItsOwnAfterPlayingField(): void
    {
        $u1 = $this->insertUser('duplicity1');
        $u2 = $this->insertUser('duplicity2');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('standard', 'in_progress', :created_by, 3)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $this->insertGamePlayer($gameId, $u2, 1);

        $this->insertGameCard($gameId, 37, 'in_play', $p1); // Duplicity
        $this->insertGameCard($gameId, 8, 'hand', $p1); // Dignity -- has its own afterPlaying choice
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $hand = $this->games->getState($gameId, $u1)['you']['hand'];
        $dignity = self::findByCardId($hand, 8);

        $repeat = self::findFieldByKey($dignity['choice_fields'], 'duplicity_repeat');
        self::assertNotNull($repeat, 'expected a duplicity_repeat field on Dignity while Duplicity is in play');
        self::assertSame('bool', $repeat['type']);

        $nested = self::findFieldByKey($dignity['choice_fields'], 'duplicity_repeat_choices');
        self::assertNotNull($nested);
        self::assertSame('nested', $nested['type']);
        self::assertCount(1, $nested['fields']);
        self::assertSame('discard_card_id', $nested['fields'][0]['key']);
    }

    public function testGetStateOmitsDuplicityFieldsWhenViewerHasNoDuplicityInPlay(): void
    {
        $u1 = $this->insertUser('noduplicity1');
        $u2 = $this->insertUser('noduplicity2');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('standard', 'in_progress', :created_by, 3)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $this->insertGamePlayer($gameId, $u2, 1);

        $this->insertGameCard($gameId, 8, 'hand', $p1); // Dignity, no Duplicity anywhere in play
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $hand = $this->games->getState($gameId, $u1)['you']['hand'];

        self::assertNull(self::findFieldByKey($hand[0]['choice_fields'], 'duplicity_repeat'));
        self::assertNull(self::findFieldByKey($hand[0]['choice_fields'], 'duplicity_repeat_choices'));
    }

    public function testGetStateOmitsDuplicityFieldsForCreativitySinceItsRawHasAfterPlayingIsFalse(): void
    {
        $u1 = $this->insertUser('creativitydup1');
        $u2 = $this->insertUser('creativitydup2');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('standard', 'in_progress', :created_by, 3)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $this->insertGamePlayer($gameId, $u2, 1);

        $this->insertGameCard($gameId, 37, 'in_play', $p1); // Duplicity
        $this->insertGameCard($gameId, 32, 'hand', $p1); // Creativity -- raw hasAfterPlaying is false
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $hand = $this->games->getState($gameId, $u1)['you']['hand'];
        $creativity = self::findByCardId($hand, 32);

        self::assertNull(self::findFieldByKey($creativity['choice_fields'], 'duplicity_repeat'));
        $copyField = self::findFieldByKey($creativity['choice_fields'], 'copy_card_id');
        self::assertNotNull($copyField);
        self::assertSame('mood', $copyField['type']);
        self::assertSame('any', $copyField['scope']);
    }

    public function testGetStatesDuplicityNestedChoicesExcludeGuilesCostFieldButKeepItsTargetField(): void
    {
        $u1 = $this->insertUser('guiledup1');
        $u2 = $this->insertUser('guiledup2');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('standard', 'in_progress', :created_by, 3)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $this->insertGamePlayer($gameId, $u2, 1);

        $this->insertGameCard($gameId, 37, 'in_play', $p1); // Duplicity
        $this->insertGameCard($gameId, 40, 'hand', $p1); // Guile -- discard cost + afterPlaying target
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $hand = $this->games->getState($gameId, $u1)['you']['hand'];
        $guile = self::findByCardId($hand, 40);

        $nested = self::findFieldByKey($guile['choice_fields'], 'duplicity_repeat_choices');
        self::assertNotNull($nested);
        self::assertCount(1, $nested['fields']);
        self::assertSame('target_mood_id', $nested['fields'][0]['key']);
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
    public function testCopySimulationOffersDuplicitysRepeatForAnAfterPlayingCandidateButNotForDuplicityItself(): void
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
        $this->insertGameCard($gameId, 8, 'in_play', $p2); // Dignity -- a copy candidate with its own afterPlaying
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $hand = $this->games->getState($gameId, $u1)['you']['hand'];
        $creativity = self::findByCardId($hand, 32);

        $dignitySim = $creativity['copy_simulation'][8];
        $repeatField = self::findFieldByKey($dignitySim['extra_fields'], 'duplicity_repeat');
        self::assertNotNull($repeatField);
        $nested = self::findFieldByKey($dignitySim['extra_fields'], 'duplicity_repeat_choices');
        self::assertNotNull($nested);
        self::assertCount(1, $nested['fields']);
        self::assertSame('discard_card_id', $nested['fields'][0]['key']);

        $duplicitySim = $creativity['copy_simulation'][37];
        self::assertNull(self::findFieldByKey($duplicitySim['extra_fields'], 'duplicity_repeat'));
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
     * playing Creativity as a copy of Dignity, with Duplicity in play and
     * a genuine duplicity_repeat submitted alongside copy_card_id, has to
     * actually invoke Dignity's own afterPlaying() twice -- once from the
     * top-level choices, once from duplicity_repeat_choices -- discarding
     * two different hand cards and boosting Creativity's own live value
     * to 5, exactly as if a real Dignity had been played and repeated.
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

        $this->games->playMood($gameId, $p1, 32, [
            'copy_card_id' => 8,
            'discard_card_id' => 3,
            'duplicity_repeat' => true,
            'duplicity_repeat_choices' => ['discard_card_id' => 4],
        ]);

        $registry = DefaultEffectRegistry::build();
        $state = (new BoardStateRepository($registry))->load($gameId);

        self::assertSame([], $state->hand($p1));
        self::assertSame([3, 4], $state->discardPile());
        self::assertTrue($state->isInPlay(32));
        self::assertSame('white', $state->colorOf(32)); // Dignity's color, confirming the copy took effect
        self::assertSame(5, $state->valueOf(32));
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

        return ['gameId' => $gameId, 'p1' => $p1, 'p2' => $p2, 'p3' => $p3];
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
}
