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
use MoodSwings\Repository\UserRepository;
use MoodSwings\Rules\DefaultEffectRegistry;
use MoodSwings\Rules\MoodPlayService;
use MoodSwings\Rules\RoundScorer;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * Issue #397: "auto-apply scoring bonuses" -- a personal preference
 * (users.auto_apply_scoring_bonuses, migration 0165, defaults to true)
 * driving GameService::advanceAutomatedTurns() -- the same turn-advancing
 * loop practice bots (issue #140) and "Auto-pass on empty hand" (see
 * AutoPassOnEmptyHandIntegrationTest) already use -- to automatically
 * answer an opted-in player's own open Enthusiasm/Passion scoring
 * decision with the obviously-correct answer (GameService::
 * autoScoringDecisionAnswer()) instead of pausing for a manual response
 * every round either card stays in play. Falls back to the ordinary
 * manual pause whenever Sneakiness was played THIS round by anyone (see
 * GameService::sneakinessPlayedThisRound()) -- see its own tests below
 * for that exception.
 */
final class AutoApplyScoringBonusesIntegrationTest extends TestCase
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
        );
    }

    /** auto_apply_scoring_bonuses defaults to 1 (on) -- see migration 0165. */
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

    private function insertGame(string $format, string $deckType, int $createdByUserId): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, deck_type, status, created_by_user_id, wins_needed)
             VALUES (:format, :deck_type, 'in_progress', :created_by, 3)"
        );
        $stmt->execute(['format' => $format, 'deck_type' => $deckType, 'created_by' => $createdByUserId]);

        return (int) $this->pdo->lastInsertId();
    }

    private function setEffectState(int $gameCardId, array $effectState): void
    {
        $this->pdo->prepare('UPDATE game_cards SET effect_state = :effect_state WHERE id = :id')
            ->execute(['effect_state' => json_encode($effectState), 'id' => $gameCardId]);
    }

    /** @return array<int, int> game_player_id => score */
    private function fetchRoundScores(int $gameId, int $roundNumber): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT game_player_id, score FROM game_round_scores gs
             JOIN game_rounds gr ON gr.id = gs.game_round_id
             WHERE gr.game_id = :game_id AND gr.round_number = :round_number'
        );
        $stmt->execute(['game_id' => $gameId, 'round_number' => $roundNumber]);

        $scores = [];
        foreach ($stmt->fetchAll() as $row) {
            $scores[(int) $row['game_player_id']] = (int) $row['score'];
        }

        return $scores;
    }

    public function testAutoAppliesEnthusiasmsBonusForAnOptedInPlayer(): void
    {
        $u1 = $this->insertUser('scoreauto1');
        $u2 = $this->insertUser('scoreauto2');
        $gameId = $this->insertGame('standard', 'structure', $u1);
        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $p2 = $this->insertGamePlayer($gameId, $u2, 1);

        $this->insertGameCard($gameId, 116, 'in_play', $p1); // Enthusiasm, value 0
        $this->insertGameCard($gameId, 8, 'in_play', $p1); // Dignity, base value 3
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $this->games->pass($gameId, $p1);
        $passResult2 = $this->games->pass($gameId, $p2);
        self::assertTrue($passResult2['pending_decision'] ?? false, 'ending the round should pause for a scoring decision');

        $result = $this->games->advanceAutomatedTurns($gameId);

        self::assertNotNull($result, 'auto_apply_scoring_bonuses defaults to on -- the loop should have auto-answered it');
        self::assertTrue($result['round_scored'] ?? false);
        self::assertSame([$p1 => 6, $p2 => 0], $this->fetchRoundScores($gameId, 1)); // base 3 + Enthusiasm's own bonus (3)
    }

    /**
     * Proves autoScoringDecisionAnswer() picks the HIGHEST-value other
     * player's mood, not just any legal one -- Discipline (6) beats
     * Dignity (3), both owned by the same opponent.
     */
    public function testAutoAppliesPassionsHighestValueOpponentMoodForAnOptedInPlayer(): void
    {
        $u1 = $this->insertUser('scoreauto3');
        $u2 = $this->insertUser('scoreauto4');
        $gameId = $this->insertGame('standard', 'structure', $u1);
        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $p2 = $this->insertGamePlayer($gameId, $u2, 1);

        $this->insertGameCard($gameId, 97, 'in_play', $p1); // Passion, value 0
        $this->insertGameCard($gameId, 8, 'in_play', $p2); // Dignity, base value 3
        $this->insertGameCard($gameId, 9, 'in_play', $p2); // Discipline, base value 6 -- the higher of the two
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $this->games->pass($gameId, $p1);
        $this->games->pass($gameId, $p2);

        $result = $this->games->advanceAutomatedTurns($gameId);

        self::assertNotNull($result);
        self::assertTrue($result['round_scored'] ?? false);
        // p1: Passion's own base (0) + Discipline via Passion (6) = 6.
        // p2 still scores BOTH their own moods regardless (3 + 6 = 9) --
        // Passion never removes the target from its owner's own score.
        self::assertSame([$p1 => 6, $p2 => 9], $this->fetchRoundScores($gameId, 1));
    }

    /**
     * autoScoringDecisionAnswer() must decline (empty answer) rather than
     * erroring when no other player has any mood in play at all to
     * target -- the round should still score normally, just without a
     * bonus.
     */
    public function testAutoDeclinesPassionWhenNoOtherPlayerHasAnyMoodInPlay(): void
    {
        $u1 = $this->insertUser('scoreauto5');
        $u2 = $this->insertUser('scoreauto6');
        $gameId = $this->insertGame('standard', 'structure', $u1);
        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $p2 = $this->insertGamePlayer($gameId, $u2, 1);

        $this->insertGameCard($gameId, 97, 'in_play', $p1); // Passion, value 0 -- p2 has nothing to target
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $this->games->pass($gameId, $p1);
        $this->games->pass($gameId, $p2);

        $result = $this->games->advanceAutomatedTurns($gameId);

        self::assertNotNull($result);
        self::assertTrue($result['round_scored'] ?? false);
        self::assertSame([$p1 => 0, $p2 => 0], $this->fetchRoundScores($gameId, 1));
    }

    public function testDoesNotAutoApplyForAPlayerWhoOptedOut(): void
    {
        $u1 = $this->insertUser('scoreauto7');
        $u2 = $this->insertUser('scoreauto8');
        (new UserRepository())->setAutoApplyScoringBonuses($u1, false);
        $gameId = $this->insertGame('standard', 'structure', $u1);
        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $p2 = $this->insertGamePlayer($gameId, $u2, 1);

        $this->insertGameCard($gameId, 116, 'in_play', $p1); // Enthusiasm
        $this->insertGameCard($gameId, 8, 'in_play', $p1); // Dignity
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $this->games->pass($gameId, $p1);
        $this->games->pass($gameId, $p2);

        self::assertNull($this->games->advanceAutomatedTurns($gameId), "an opted-out player's own scoring decision should NOT be auto-answered");

        $state = $this->games->getState($gameId, $u1);
        self::assertSame('enthusiasm_extra_score', $state['round']['pending_decision']['decision_type'] ?? null, 'the decision should still be open, waiting on a manual answer');
    }

    /**
     * The Sneakiness exception (issue #397's own "Scope" section):
     * whenever Sneakiness was played THIS round by ANY seated player
     * (including one who isn't even the one being auto-answered for),
     * every opted-in player's own scoring decision falls back to a manual
     * answer for that round, since maximizing your own score is no
     * longer necessarily correct once it might get swapped away.
     */
    public function testDoesNotAutoApplyWhenSneakinessWasPlayedThisRound(): void
    {
        $u1 = $this->insertUser('scoreauto9');
        $u2 = $this->insertUser('scoreauto10');
        $gameId = $this->insertGame('standard', 'structure', $u1);
        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $p2 = $this->insertGamePlayer($gameId, $u2, 1);

        $this->insertGameCard($gameId, 116, 'in_play', $p1); // Enthusiasm
        $this->insertGameCard($gameId, 8, 'in_play', $p1); // Dignity
        $sneakinessId = $this->insertGameCard($gameId, 51, 'in_play', $p2); // Sneakiness -- someone else's, still counts
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);
        $this->setEffectState($sneakinessId, ['playedInRound' => 1]); // played THIS round

        $this->games->pass($gameId, $p1);
        $this->games->pass($gameId, $p2);

        self::assertNull($this->games->advanceAutomatedTurns($gameId), 'Sneakiness played this round should fall back to a manual answer even for an opted-in player');

        $state = $this->games->getState($gameId, $u1);
        self::assertSame('enthusiasm_extra_score', $state['round']['pending_decision']['decision_type'] ?? null);
    }

    /**
     * The other half of the Sneakiness exception: a Sneakiness played in
     * an EARLIER round has already resolved its own swap and has no
     * effect on THIS round's scoring, even though the physical card
     * stays on the table afterward -- auto-apply should proceed normally.
     */
    public function testStillAutoAppliesWhenSneakinessWasPlayedInAnEarlierRound(): void
    {
        $u1 = $this->insertUser('scoreauto11');
        $u2 = $this->insertUser('scoreauto12');
        $gameId = $this->insertGame('standard', 'structure', $u1);
        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $p2 = $this->insertGamePlayer($gameId, $u2, 1);

        $this->insertGameCard($gameId, 116, 'in_play', $p1); // Enthusiasm
        $this->insertGameCard($gameId, 8, 'in_play', $p1); // Dignity
        $sneakinessId = $this->insertGameCard($gameId, 51, 'in_play', $p2); // Sneakiness, played round 1 -- already resolved
        $this->insertGameRound($gameId, 2, $p1, $p1, 1); // current round is 2
        $this->setEffectState($sneakinessId, ['playedInRound' => 1]);

        $this->games->pass($gameId, $p1);
        $this->games->pass($gameId, $p2);

        $result = $this->games->advanceAutomatedTurns($gameId);

        self::assertNotNull($result, "a Sneakiness played in an earlier round should NOT block this round's auto-apply");
        self::assertTrue($result['round_scored'] ?? false);
    }
}
