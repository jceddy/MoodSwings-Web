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
 * "Auto-pass on empty hand": a personal preference (users.
 * auto_pass_on_empty_hand, migration 0096, defaults to true) driving
 * GameService::advanceAutomatedTurns() -- the same turn-advancing loop
 * practice bots (issue #140, see BotGameplayIntegrationTest) already
 * use -- to automatically pass on an opted-in player's behalf whenever
 * it's their turn and they have no legal play at all (GameService::
 * candidatePlayCardIds(), hand plus the whole discard pile, filtered
 * through MoodPlayService::isPlayable()) -- NOT simply whenever their
 * hand is empty, since Angst/Harmony/Grief/Melancholy can each leave a
 * completely legal discard-sourced play available with an empty hand
 * (see testDoesNotAutoPassAnOptedInPlayerWithAnEmptyHandButAUsableDiscardSourcedGrant()
 * below, a real bug this regression covers).
 */
final class AutoPassOnEmptyHandIntegrationTest extends TestCase
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

    /** auto_pass_on_empty_hand defaults to 1 (on) -- see migration 0096. */
    private function insertUser(string $username): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO users (username, email, password_hash, email_verified_at)
             VALUES (:username, :email, 'hash', NOW())"
        );
        $stmt->execute(['username' => $username, 'email' => "{$username}@example.com"]);

        return (int) $this->pdo->lastInsertId();
    }

    private function insertBotUser(string $username): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO users (username, email, password_hash, email_verified_at, is_bot)
             VALUES (:username, :email, 'hash', NOW(), 1)"
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

    private function insertGameCard(int $gameId, int $cardId, string $zone, ?int $owner = null): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO game_cards (game_id, card_id, zone, owner_game_player_id) VALUES (:game_id, :card_id, :zone, :owner)'
        );
        $stmt->execute(['game_id' => $gameId, 'card_id' => $cardId, 'zone' => $zone, 'owner' => $owner]);
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

    private function fetchRound(int $gameId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM game_rounds WHERE game_id = :game_id AND status = 'in_progress' ORDER BY round_number DESC LIMIT 1"
        );
        $stmt->execute(['game_id' => $gameId]);

        return $stmt->fetch();
    }

    public function testAutoPassesForAnOptedInPlayerWithAnEmptyHand(): void
    {
        $u1 = $this->insertUser('human1');
        $u2 = $this->insertUser('human2');
        $gameId = $this->insertGame('standard', 'structure', $u1);
        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $p2 = $this->insertGamePlayer($gameId, $u2, 1);

        // p1's hand is empty; opted in by default (see insertUser()). p2
        // has a card so the loop stops there instead of ALSO auto-passing
        // p2 (also opted in by default) and scoring the round out from
        // under this test.
        $this->insertGameCard($gameId, 8, 'hand', $p2);
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $result = $this->games->advanceAutomatedTurns($gameId);

        self::assertNotNull($result);
        $round = $this->fetchRound($gameId);
        self::assertSame($p2, (int) $round['current_turn_game_player_id']);
    }

    /**
     * The real bug this regression covers: an opted-in player's HAND can
     * be empty while they still have a completely legal play available,
     * sourced from the discard pile instead (Angst/Harmony/Grief/
     * Melancholy's own grants -- see AngstEffect's own docblock). The old
     * "hand is empty => nothing else legal" assumption silently passed
     * this away; candidatePlayCardIds() (hand plus the whole discard
     * pile) is what makes this test pass now. game_rounds.pending_play_grants
     * (BoardStateRepository::load()'s own restore path) is written
     * directly here to simulate a grant that already existed BEFORE this
     * call, the same as it would right after Angst's own afterPlaying()
     * resolved and the player declined a Duplicity repeat -- see
     * MoodPlayService::resolveDuplicityRepeatOffer().
     */
    public function testDoesNotAutoPassAnOptedInPlayerWithAnEmptyHandButAUsableDiscardSourcedGrant(): void
    {
        $u1 = $this->insertUser('human1');
        $u2 = $this->insertUser('human2');
        $gameId = $this->insertGame('standard', 'structure', $u1);
        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $this->insertGamePlayer($gameId, $u2, 1);

        // p1's hand is empty, but Charity (no "to play" cost) sits in the
        // shared discard pile, and p1 has an outstanding discard-sourced
        // play grant (exactly what Angst's own afterPlaying() leaves
        // behind) -- there IS a legal play here, so this must NOT auto-pass.
        $this->insertGameCard($gameId, 3, 'discard', $p1); // Charity
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);
        $this->pdo->prepare('UPDATE game_rounds SET pending_play_grants = :grants WHERE game_id = :game_id')
            ->execute(['grants' => json_encode([['source' => 'discard']]), 'game_id' => $gameId]);

        self::assertNull($this->games->advanceAutomatedTurns($gameId));
        $round = $this->fetchRound($gameId);
        self::assertSame($p1, (int) $round['current_turn_game_player_id']); // untouched
    }

    public function testDoesNotAutoPassAnOptedInPlayerWithCardsInHand(): void
    {
        $u1 = $this->insertUser('human1');
        $u2 = $this->insertUser('human2');
        $gameId = $this->insertGame('standard', 'structure', $u1);
        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $this->insertGamePlayer($gameId, $u2, 1);

        $this->insertGameCard($gameId, 8, 'hand', $p1); // Dignity -- opted in, but not empty-handed
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        self::assertNull($this->games->advanceAutomatedTurns($gameId));
        $round = $this->fetchRound($gameId);
        self::assertSame($p1, (int) $round['current_turn_game_player_id']); // untouched
    }

    public function testDoesNotAutoPassAPlayerWhoOptedOut(): void
    {
        $u1 = $this->insertUser('human1');
        $u2 = $this->insertUser('human2');
        (new UserRepository())->setAutoPassOnEmptyHand($u1, false);
        $gameId = $this->insertGame('standard', 'structure', $u1);
        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $this->insertGamePlayer($gameId, $u2, 1);

        // p1's hand is empty, but they've opted out.
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        self::assertNull($this->games->advanceAutomatedTurns($gameId));
        $round = $this->fetchRound($gameId);
        self::assertSame($p1, (int) $round['current_turn_game_player_id']); // untouched
    }

    /**
     * Three empty-handed, opted-in players in a row are all auto-passed
     * within the SAME call before landing on the one player who actually
     * has a card to play -- proving the loop keeps going rather than
     * stopping after a single auto-pass.
     */
    public function testChainsThroughMultipleEmptyHandedOptedInPlayersInARow(): void
    {
        $u1 = $this->insertUser('human1');
        $u2 = $this->insertUser('human2');
        $u3 = $this->insertUser('human3');
        $u4 = $this->insertUser('human4');
        $gameId = $this->insertGame('standard', 'structure', $u1);
        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $this->insertGamePlayer($gameId, $u2, 1);
        $this->insertGamePlayer($gameId, $u3, 2);
        $p4 = $this->insertGamePlayer($gameId, $u4, 3);

        $this->insertGameCard($gameId, 8, 'hand', $p4); // only p4 has a card
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $result = $this->games->advanceAutomatedTurns($gameId);

        self::assertNotNull($result);
        $round = $this->fetchRound($gameId);
        self::assertSame($p4, (int) $round['current_turn_game_player_id']);
    }

    /**
     * A bot's own turn and an opted-in empty-handed human's turn
     * interleave correctly within the same call -- the bot goes first
     * (nothing playable, passes), then the human (also empty-handed,
     * auto-passes), landing on the real player who actually has a card,
     * all in one advanceAutomatedTurns() call.
     */
    public function testInterleavesABotsTurnAndAnOptedInHumansAutoPassInTheSameCall(): void
    {
        $botUserId = $this->insertBotUser('bot1'); // empty hand -- passes
        $u2 = $this->insertUser('human1'); // empty hand, opted in -- auto-passes
        $u3 = $this->insertUser('human2'); // has a card -- the loop stops here
        $gameId = $this->insertGame('standard', 'structure', $botUserId);
        // Seated in turn order (bot first) so advanceTurn()'s own seat
        // rotation visits them bot -> p2 -> p3, matching the comments
        // above.
        $botPlayerId = $this->insertGamePlayer($gameId, $botUserId, 0);
        $this->insertGamePlayer($gameId, $u2, 1);
        $p3 = $this->insertGamePlayer($gameId, $u3, 2);

        $this->insertGameCard($gameId, 8, 'hand', $p3);
        $this->insertGameRound($gameId, 1, $botPlayerId, $botPlayerId, 1);

        $result = $this->games->advanceAutomatedTurns($gameId);

        self::assertNotNull($result);
        $round = $this->fetchRound($gameId);
        self::assertSame($p3, (int) $round['current_turn_game_player_id']);
    }
}
