<?php

declare(strict_types=1);

namespace MoodSwings\Tests\Game;

use MoodSwings\Deck\UserDecklistService;
use MoodSwings\Friends\FriendshipService;
use MoodSwings\Game\BoardStateRepository;
use MoodSwings\Game\GameService;
use MoodSwings\Game\ReplayStateBuilder;
use MoodSwings\Repository\FriendshipRepository;
use MoodSwings\Repository\UserDecklistRepository;
use MoodSwings\Game\Exceptions\GameStateException;
use MoodSwings\Repository\UserRepository;
use MoodSwings\Rules\DefaultEffectRegistry;
use MoodSwings\Rules\MoodPlayService;
use MoodSwings\Rules\RoundScorer;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * "Pause at the start of your turn" (reported live: "add a user setting
 * to pause at the end of turn - if the user has this setting enabled,
 * then a game should not advance to that user's turn, until they click
 * an 'advance turn' button - this is to allow users to more clearly see
 * what happened during a previous turn before/after scoring effects
 * happen - sometimes even with the log text available it is difficult
 * to figure out for many users"): users.pause_before_own_turn
 * (migration 0263, defaults to false) makes GameService::
 * notifyItsYourTurn() -- the single hook already used for the "your
 * turn" push/Discord notification, fired for every genuine turn
 * handoff, an ordinary mid-round pass-the-turn OR a brand new round
 * starting after scoring -- also set the new round's own
 * turn_pending_acknowledgment flag. GameService::playMood()/pass() both
 * refuse to act (assertTurnAcknowledged()) while it's set; only
 * GameService::acknowledgeTurnStart() (POST /games/advance-turn) clears
 * it.
 */
final class PauseBeforeOwnTurnIntegrationTest extends TestCase
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
        $userDecklists = new UserDecklistService(
            new UserDecklistRepository(),
            new FriendshipService(new UserRepository(), new FriendshipRepository()),
        );
        $this->games = new GameService(
            new BoardStateRepository($registry),
            new MoodPlayService($registry),
            new RoundScorer(),
            $userDecklists,
            new ReplayStateBuilder($registry),
            spawnAutomatedTurnRecheckProcesses: false,
        );
    }

    /** pause_before_own_turn defaults to 0 (off) -- see migration 0263. */
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

    /** @return int game_cards.id */
    private function insertGameCard(int $gameId, int $cardId, string $zone, ?int $owner = null): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO game_cards (game_id, card_id, zone, owner_game_player_id) VALUES (:game_id, :card_id, :zone, :owner)'
        );
        $stmt->execute(['game_id' => $gameId, 'card_id' => $cardId, 'zone' => $zone, 'owner' => $owner]);

        return (int) $this->pdo->lastInsertId();
    }

    private function insertGameRound(int $gameId, int $roundNumber, int $firstPlayerId, int $currentTurnPlayerId, int $playsRemaining): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO game_rounds (game_id, round_number, first_game_player_id, current_turn_game_player_id, plays_remaining, status)
             VALUES (:game_id, :round_number, :first_player, :current_turn, :plays_remaining, 'in_progress')"
        );
        $stmt->execute([
            'game_id' => $gameId,
            'round_number' => $roundNumber,
            'first_player' => $firstPlayerId,
            'current_turn' => $currentTurnPlayerId,
            'plays_remaining' => $playsRemaining,
        ]);
    }

    private function insertGame(string $format, string $deckType, int $createdByUserId, int $winsNeeded = 3): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, deck_type, status, created_by_user_id, wins_needed)
             VALUES (:format, :deck_type, 'in_progress', :created_by, :wins_needed)"
        );
        $stmt->execute(['format' => $format, 'deck_type' => $deckType, 'created_by' => $createdByUserId, 'wins_needed' => $winsNeeded]);

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

    public function testHandoffToAnOptedInPlayerSetsThePendingFlag(): void
    {
        $u1 = $this->insertUser('human1');
        $u2 = $this->insertUser('human2');
        (new UserRepository())->setPauseBeforeOwnTurn($u2, true);
        $gameId = $this->insertGame('standard', 'structure', $u1);
        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $p2 = $this->insertGamePlayer($gameId, $u2, 1);

        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $this->games->pass($gameId, $p1);

        $round = $this->fetchRound($gameId);
        self::assertSame($p2, (int) $round['current_turn_game_player_id']);
        self::assertSame(1, (int) $round['turn_pending_acknowledgment']);
    }

    public function testHandoffToAPlayerWhoOptedOutLeavesTheFlagClear(): void
    {
        $u1 = $this->insertUser('human1');
        $u2 = $this->insertUser('human2'); // opted out by default
        $gameId = $this->insertGame('standard', 'structure', $u1);
        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $p2 = $this->insertGamePlayer($gameId, $u2, 1);

        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $this->games->pass($gameId, $p1);

        $round = $this->fetchRound($gameId);
        self::assertSame($p2, (int) $round['current_turn_game_player_id']);
        self::assertSame(0, (int) $round['turn_pending_acknowledgment']);
    }

    public function testPassIsRejectedWhileTheTurnIsPendingAcknowledgment(): void
    {
        $u1 = $this->insertUser('human1');
        $u2 = $this->insertUser('human2');
        (new UserRepository())->setPauseBeforeOwnTurn($u2, true);
        $gameId = $this->insertGame('standard', 'structure', $u1);
        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $p2 = $this->insertGamePlayer($gameId, $u2, 1);
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);
        $this->games->pass($gameId, $p1); // hands the turn to p2, setting the flag

        $this->expectException(GameStateException::class);
        $this->games->pass($gameId, $p2);
    }

    public function testPlayMoodIsRejectedWhileTheTurnIsPendingAcknowledgment(): void
    {
        $u1 = $this->insertUser('human1');
        $u2 = $this->insertUser('human2');
        (new UserRepository())->setPauseBeforeOwnTurn($u2, true);
        $gameId = $this->insertGame('standard', 'structure', $u1);
        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $p2 = $this->insertGamePlayer($gameId, $u2, 1);
        $this->insertGameCard($gameId, 55, 'hand', $p2); // Apathy
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);
        $this->games->pass($gameId, $p1); // hands the turn to p2, setting the flag

        $this->expectException(GameStateException::class);
        $this->games->playMood($gameId, $p2, 55, []);
    }

    public function testAcknowledgingTurnStartUnlocksPlay(): void
    {
        $u1 = $this->insertUser('human1');
        $u2 = $this->insertUser('human2');
        (new UserRepository())->setPauseBeforeOwnTurn($u2, true);
        $gameId = $this->insertGame('standard', 'structure', $u1);
        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $p2 = $this->insertGamePlayer($gameId, $u2, 1);
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);
        $this->games->pass($gameId, $p1); // hands the turn to p2, setting the flag

        $this->games->acknowledgeTurnStart($gameId, $p2);

        $round = $this->fetchRound($gameId);
        self::assertSame(0, (int) $round['turn_pending_acknowledgment']);
        // No longer blocked -- pass() now succeeds instead of throwing.
        $result = $this->games->pass($gameId, $p2);
        self::assertTrue($result['round_scored']);
    }

    public function testAcknowledgeTurnStartRejectsSomeoneOtherThanTheCurrentTurnHolder(): void
    {
        $u1 = $this->insertUser('human1');
        $u2 = $this->insertUser('human2');
        (new UserRepository())->setPauseBeforeOwnTurn($u2, true);
        $gameId = $this->insertGame('standard', 'structure', $u1);
        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $p2 = $this->insertGamePlayer($gameId, $u2, 1);
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);
        $this->games->pass($gameId, $p1); // hands the turn to p2, setting the flag

        $this->expectException(GameStateException::class);
        $this->games->acknowledgeTurnStart($gameId, $p1);
    }

    public function testAcknowledgeTurnStartIsAHarmlessNoOpWhenNothingIsPending(): void
    {
        $u1 = $this->insertUser('human1');
        $gameId = $this->insertGame('standard', 'structure', $u1);
        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $this->insertGamePlayer($gameId, $this->insertUser('human2'), 1);
        $this->insertGameRound($gameId, 1, $p1, $p1, 1); // p1 opted out, flag never set

        $result = $this->games->acknowledgeTurnStart($gameId, $p1);

        self::assertFalse($result['round_scored']);
        $round = $this->fetchRound($gameId);
        self::assertSame(0, (int) $round['turn_pending_acknowledgment']);
    }

    /**
     * The decision made explicitly for this feature: pausing still takes
     * priority even when the SAME player also has auto_pass_on_empty_hand
     * on and an empty hand -- advanceAutomatedTurns() must not auto-pass
     * them until they've acknowledged, so they still get a chance to see
     * what just happened first. Once acknowledged, the very next
     * advanceAutomatedTurns() call auto-passes them immediately, exactly
     * as it would have from the start had pausing been off.
     */
    public function testAutoPassOnEmptyHandWaitsForAcknowledgmentFirst(): void
    {
        $u1 = $this->insertUser('human1');
        $u2 = $this->insertUser('human2');
        $userRepository = new UserRepository();
        $userRepository->setPauseBeforeOwnTurn($u2, true);
        $userRepository->setAutoPassOnEmptyHand($u2, true);
        $gameId = $this->insertGame('standard', 'structure', $u1);
        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $p2 = $this->insertGamePlayer($gameId, $u2, 1);
        // p1 keeps a legal play of its own, so the round can't score out
        // from under this test the instant p2 is auto-passed -- this
        // test only cares whether/when THAT auto-pass itself fires.
        $this->insertGameCard($gameId, 8, 'hand', $p1); // Dignity
        // p2's hand is empty -- would ordinarily auto-pass immediately.
        // current_turn_game_player_id/turn_pending_acknowledgment are
        // set directly here (mirroring AutoPassOnEmptyHandIntegrationTest's
        // own fixtures) rather than via a real pass() handoff, so this
        // stays a pure single-round test of the pause/auto-pass
        // interaction rather than also exercising round-to-round scoring.
        $this->insertGameRound($gameId, 1, $p1, $p2, 1);
        $this->pdo->prepare('UPDATE game_rounds SET turn_pending_acknowledgment = 1 WHERE game_id = :game_id')
            ->execute(['game_id' => $gameId]);

        self::assertNull($this->games->advanceAutomatedTurns($gameId));
        $round = $this->fetchRound($gameId);
        self::assertSame($p2, (int) $round['current_turn_game_player_id']); // still waiting, untouched
        self::assertSame(1, (int) $round['turn_pending_acknowledgment']);

        $this->games->acknowledgeTurnStart($gameId, $p2);
        $result = $this->games->advanceAutomatedTurns($gameId);

        self::assertNotNull($result); // now auto-passed
        $round = $this->fetchRound($gameId);
        self::assertSame($p1, (int) $round['current_turn_game_player_id']); // handed back to p1, who still has a card
    }

    /**
     * The scenario reported live: a brand new round starting after
     * scoring is JUST as much a "your turn" moment as an ordinary
     * mid-round handoff -- finishScoringAndAdvance()'s own raw INSERT
     * (never routed through updateRoundTurnState()) still reaches
     * notifyItsYourTurn(), so the new round's own first player gets the
     * same pending-acknowledgment treatment if they opted in.
     */
    public function testANewRoundStartingAfterScoringAlsoSetsThePendingFlagForTheWinner(): void
    {
        $u1 = $this->insertUser('human1');
        $u2 = $this->insertUser('human2');
        (new UserRepository())->setPauseBeforeOwnTurn($u1, true);
        $gameId = $this->insertGame('standard', 'structure', $u1, winsNeeded: 3);
        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $p2 = $this->insertGamePlayer($gameId, $u2, 1);
        $apathyId = $this->insertGameCard($gameId, 55, 'hand', $p1); // Apathy, value 4 -- p1 wins the round
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $result = $this->games->playMood($gameId, $p1, $apathyId, []);
        self::assertFalse($result['round_scored']); // p2 still needs to pass first
        $result = $this->games->pass($gameId, $p2);
        self::assertTrue($result['round_scored']);

        // p1 won round 1 (higher score), so round 2 starts with them as
        // the current turn holder -- opted in, so it's gated.
        $round = $this->fetchRound($gameId);
        self::assertSame(2, (int) $round['round_number']);
        self::assertSame($p1, (int) $round['current_turn_game_player_id']);
        self::assertSame(1, (int) $round['turn_pending_acknowledgment']);

        $this->expectException(GameStateException::class);
        $this->games->pass($gameId, $p1);
    }

    /**
     * The exact scenario reported live (migration 0275): "if an opponent
     * plays recklessness and steals one of my boredom, I want to be able
     * to see the board State with their recklessness in play and my
     * boredom on their side before I move on to the next round." p1
     * steals p2's Boredom via Recklessness during round 1; round 1 scores
     * with p1 the winner (they now hold both Recklessness and Boredom,
     * worth more than p2's now-empty board), so p1 -- who opted into the
     * pause -- becomes round 2's own current turn holder. Before
     * acknowledging, GET /games/state must show the board exactly as it
     * stood the instant round 1 ended: Recklessness still in play (not
     * yet bottomed) and Boredom still under p1's own control (not yet
     * given back to p2) -- NOT the fully-resolved after-scoring state.
     * Once acknowledged, the same call must flip over to the real,
     * already-advanced board.
     */
    public function testGetStateShowsTheFrozenPreAfterScoringBoardUntilAcknowledged(): void
    {
        $u1 = $this->insertUser('human1');
        $u2 = $this->insertUser('human2');
        (new UserRepository())->setPauseBeforeOwnTurn($u1, true);
        $gameId = $this->insertGame('standard', 'structure', $u1, winsNeeded: 3);
        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $p2 = $this->insertGamePlayer($gameId, $u2, 1);
        // Boredom is genuinely PLAYED below (not pre-seeded straight into
        // 'in_play') -- ReplayStateBuilder only ever knows about a card's
        // entering play via its own logged 'mood_played' event, so a card
        // planted directly into the database would be invisible to the
        // frozen-board reconstruction this test is actually proving out.
        $boredomId = $this->insertGameCard($gameId, 83, 'hand', $p2); // Boredom
        $recklessnessId = $this->insertGameCard($gameId, 100, 'hand', $p1); // Recklessness
        $this->insertGameRound($gameId, 1, $p2, $p2, 1); // p2 goes first this round

        $this->games->playMood($gameId, $p2, $boredomId, []);
        // Turn handing to p1 mid-round is ALSO a "your turn" moment (see
        // notifyItsYourTurn()'s own docblock) -- p1 opted into the pause,
        // so this needs its own acknowledgment too, same as any other
        // handoff would.
        $this->games->acknowledgeTurnStart($gameId, $p1);
        $result = $this->games->playMood($gameId, $p1, $recklessnessId, ['target_mood_id' => $boredomId]);
        // Recklessness alone leaves p1 controlling TWO of their own
        // pending after-scoring effects once the round ends (its own
        // "bottom and draw" self-tag, plus the "return Boredom to its
        // owner" tag it placed on Boredom) -- an after-scoring ORDER
        // decision (same mechanism GameServiceIntegrationTest's own
        // Betrayal/Recklessness tests exercise) must be answered before
        // the round can actually finish scoring. Order is immaterial
        // here (the two effects touch different cards), so this just
        // answers with whatever default order the server offers.
        self::assertTrue($result['pending_decision'] ?? false);
        $orderDecision = $this->games->getState($gameId, $u1)['round']['pending_decision'];
        self::assertSame('after_scoring_order', $orderDecision['decision_type']);
        $orderedCardIds = array_column($orderDecision['field']['cards'], 'card_id');
        $result = $this->games->respondToDecision($gameId, $p1, ['ordered_card_ids' => $orderedCardIds]);
        self::assertTrue($result['round_scored']);

        $round = $this->fetchRound($gameId);
        self::assertSame(2, (int) $round['round_number']);
        self::assertSame($p1, (int) $round['current_turn_game_player_id']);
        self::assertSame(1, (int) $round['turn_pending_acknowledgment']);

        $frozenState = $this->games->getState($gameId, $u1);
        $frozenInPlayByCardId = array_column($frozenState['in_play'], null, 'card_id');
        self::assertArrayHasKey($recklessnessId, $frozenInPlayByCardId, 'Recklessness should still be shown in play, not yet bottomed');
        self::assertArrayHasKey($boredomId, $frozenInPlayByCardId, 'Boredom should still be shown in play');
        self::assertSame($p1, $frozenInPlayByCardId[$boredomId]['owner_game_player_id'], 'Boredom should still be shown under the taker, not yet given back');

        $this->games->acknowledgeTurnStart($gameId, $p1);

        $liveState = $this->games->getState($gameId, $u1);
        $liveInPlayByCardId = array_column($liveState['in_play'], null, 'card_id');
        self::assertArrayNotHasKey($recklessnessId, $liveInPlayByCardId, 'Recklessness should now be bottomed');
        self::assertArrayHasKey($boredomId, $liveInPlayByCardId, 'Boredom should still be in play');
        self::assertSame($p2, $liveInPlayByCardId[$boredomId]['owner_game_player_id'], 'Boredom should now be back with its original owner');
    }

    /**
     * Once p1 has genuinely acted during round 2 (not just acknowledged
     * -- an actual card played), p2's own later turn that same round
     * must NOT replay round 1's stale watermark -- see
     * GameService::roundHasAnyPlayedCard()'s own docblock. p2 should see
     * whatever p1 actually did in round 2, not a snapshot from before
     * round 2 even started.
     */
    public function testALaterHandoffWithinTheSameRoundNeverReplaysTheStaleWatermark(): void
    {
        $u1 = $this->insertUser('human1');
        $u2 = $this->insertUser('human2');
        (new UserRepository())->setPauseBeforeOwnTurn($u2, true);
        $gameId = $this->insertGame('standard', 'structure', $u1, winsNeeded: 3);
        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $p2 = $this->insertGamePlayer($gameId, $u2, 1);
        $boredomId = $this->insertGameCard($gameId, 83, 'in_play', $p2); // Boredom
        $recklessnessId = $this->insertGameCard($gameId, 100, 'hand', $p1); // Recklessness
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $this->games->playMood($gameId, $p1, $recklessnessId, ['target_mood_id' => $boredomId]);
        // p2 opted into the pause, so this first-ever handoff to them
        // needs its own acknowledgment before they can pass.
        $this->games->acknowledgeTurnStart($gameId, $p2);
        $this->games->pass($gameId, $p2);
        // Recklessness alone leaves p1 controlling two of their own
        // pending after-scoring effects -- see the other test's own
        // identical comment for why this order decision exists at all.
        $orderDecision = $this->games->getState($gameId, $u1)['round']['pending_decision'];
        $orderedCardIds = array_column($orderDecision['field']['cards'], 'card_id');
        $this->games->respondToDecision($gameId, $p1, ['ordered_card_ids' => $orderedCardIds]); // scores round 1; p1 wins, becomes round 2's first player

        // p1 (no pause preference) plays a real card during round 2 --
        // Courage (id 7, value 1) -- before it becomes p2's own turn.
        $courageId = $this->insertGameCard($gameId, 7, 'hand', $p1);
        $round = $this->fetchRound($gameId);
        self::assertSame(0, (int) $round['turn_pending_acknowledgment'], 'p1 never opted in, so round 2 starts unblocked');
        $this->games->playMood($gameId, $p1, $courageId, []);

        $round = $this->fetchRound($gameId);
        self::assertSame($p2, (int) $round['current_turn_game_player_id']);
        self::assertSame(1, (int) $round['turn_pending_acknowledgment'], 'p2 opted in, so their own handoff within round 2 is still gated');

        // p2's own view must show Courage (p1's real round-2 play), NOT
        // the stale round-1-end snapshot, which predates it entirely.
        $state = $this->games->getState($gameId, $u2);
        $inPlayCardIds = array_column($state['in_play'], 'card_id');
        self::assertContains($courageId, $inPlayCardIds, 'the frozen watermark must not hide a real play made during round 2 itself');
    }
}
