<?php

declare(strict_types=1);

namespace MoodSwings\Tests\Rules;

use MoodSwings\Deck\UserDecklistService;
use MoodSwings\Friends\FriendshipService;
use MoodSwings\Game\BoardStateRepository;
use MoodSwings\Game\GameService;
use MoodSwings\Game\ReplayStateBuilder;
use MoodSwings\Repository\FriendshipRepository;
use MoodSwings\Repository\UserDecklistRepository;
use MoodSwings\Repository\UserRepository;
use MoodSwings\Rules\DefaultEffectRegistry;
use MoodSwings\Rules\Exceptions\IllegalPlayException;
use MoodSwings\Rules\MoodPlayService;
use MoodSwings\Rules\RoundScorer;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * Issue #524: engine-verifies every puzzle seeded by
 * database/migrations/0394_add_puzzle_collection.sql -- for each one,
 * plays the intended solution through the real GameService/MoodPlayService
 * pipeline (createPuzzleAttempt() -> playMood() -> checkPuzzleGoal()) and
 * asserts the attempt actually ends up completed, and, where the puzzle
 * has a tempting wrong line, asserts that line does NOT solve it. This is
 * both the pre-ship verification the feature's own plan calls for and a
 * permanent regression test -- a future card-effect change that silently
 * breaks a puzzle's solvability gets caught here.
 */
final class PuzzleContentTest extends TestCase
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
        $pdo->exec('TRUNCATE TABLE puzzle_solves');
        $pdo->exec('TRUNCATE TABLE game_events');
        $pdo->exec('TRUNCATE TABLE game_pending_decisions');
        $pdo->exec('TRUNCATE TABLE game_pending_decision_batches');
        $pdo->exec('TRUNCATE TABLE game_cards');
        $pdo->exec('TRUNCATE TABLE game_rounds');
        $pdo->exec('TRUNCATE TABLE game_players');
        $pdo->exec('TRUNCATE TABLE games');
        $pdo->exec('TRUNCATE TABLE user_achievements');
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

    private function insertUser(string $username): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO users (username, email, password_hash, email_verified_at)
             VALUES (:username, :email, 'hash', NOW())"
        );
        $stmt->execute(['username' => $username, 'email' => "{$username}@example.com"]);

        return (int) $this->pdo->lastInsertId();
    }

    private function puzzleIdForSlug(string $slug): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM puzzles WHERE slug = :slug');
        $stmt->execute(['slug' => $slug]);

        return (int) $stmt->fetchColumn();
    }

    /** @return array{gameId: int, gamePlayerId: int} */
    private function attempt(string $slug): array
    {
        $userId = $this->insertUser("solver_{$slug}_" . bin2hex(random_bytes(4)));
        $gameId = $this->games->createPuzzleAttempt($userId, $this->puzzleIdForSlug($slug));

        $stmt = $this->pdo->prepare('SELECT id FROM game_players WHERE game_id = :game_id');
        $stmt->execute(['game_id' => $gameId]);

        return ['gameId' => $gameId, 'gamePlayerId' => (int) $stmt->fetchColumn()];
    }

    /**
     * Plays a mood and, if Duplicity is in play and the card just played
     * has an after-playing ability of its own, automatically declines the
     * resulting "repeat it again?" offer -- none of these puzzles need
     * the repeat, so a real player would just decline it too. Without
     * this, playMood() itself would return with pending_decision => true
     * instead of resolving the play (see MoodPlayService's
     * duplicityRepeatOfferRequest()).
     */
    private function play(int $gameId, int $gamePlayerId, int $cardId, array $choices = []): array
    {
        $result = $this->games->playMood($gameId, $gamePlayerId, $cardId, $choices);
        if ($result['pending_decision'] ?? false) {
            $result = $this->games->respondToDecision($gameId, $gamePlayerId, ['duplicity_repeat' => ['repeat' => false]]);
        }

        return $result;
    }

    /** The current in-game instance id of the one card matching $catalogCardId in $zone (there's never more than one per zone in these puzzles). */
    private function instanceId(int $gameId, int $catalogCardId, string $zone): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM game_cards WHERE game_id = :game_id AND card_id = :card_id AND zone = :zone LIMIT 1'
        );
        $stmt->execute(['game_id' => $gameId, 'card_id' => $catalogCardId, 'zone' => $zone]);
        $id = $stmt->fetchColumn();
        self::assertNotFalse($id, "No card {$catalogCardId} in zone '{$zone}' for game {$gameId}");

        return (int) $id;
    }

    private function assertGameSolved(int $gameId, int $gamePlayerId): void
    {
        $stmt = $this->pdo->prepare('SELECT status, winner_game_player_id FROM games WHERE id = :id');
        $stmt->execute(['id' => $gameId]);
        $row = $stmt->fetch();

        self::assertSame('completed', $row['status']);
        self::assertSame($gamePlayerId, (int) $row['winner_game_player_id']);
    }

    private function assertGameNotSolved(int $gameId): void
    {
        $stmt = $this->pdo->prepare('SELECT status FROM games WHERE id = :id');
        $stmt->execute(['id' => $gameId]);

        self::assertSame('in_progress', $stmt->fetchColumn());
    }

    public function testOneFellSwoopSolvedByFriendlinessKindnessCharityComplacency(): void
    {
        ['gameId' => $gameId, 'gamePlayerId' => $p] = $this->attempt('one-fell-swoop');

        $result = null;
        foreach ([13, 17, 3, 5] as $catalogCardId) { // Friendliness, Kindness, Charity, Complacency
            $result = $this->play($gameId, $p, $this->instanceId($gameId, $catalogCardId, 'hand'), []);
        }

        self::assertTrue($result['game_completed']);
        $this->assertGameSolved($gameId, $p);
    }

    public function testOneFellSwoopObviousOrderDoesNotSolveIt(): void
    {
        ['gameId' => $gameId, 'gamePlayerId' => $p] = $this->attempt('one-fell-swoop');

        // Charity(1) -> Complacency(4): Complacency has no ability, so the
        // turn refreshes with Friendliness/Kindness still in hand -- see
        // advancePuzzleTurn(). Playing on afterward still empties the
        // hand, but only after that refresh, which disqualifies it.
        $this->play($gameId, $p, $this->instanceId($gameId, 3, 'hand'), []);
        $this->play($gameId, $p, $this->instanceId($gameId, 5, 'hand'), []);
        $this->play($gameId, $p, $this->instanceId($gameId, 13, 'hand'), []);
        $result = $this->play($gameId, $p, $this->instanceId($gameId, 17, 'hand'), []);

        self::assertFalse($result['game_completed']);
        $this->assertGameNotSolved($gameId);
    }

    /**
     * Bug caught live via a manual smoke test through the real HTTP
     * route (which -- unlike every other test here -- calls
     * advanceAutomatedTurns() right after playMood(), exactly like
     * POST /games/play does): every user defaults to
     * auto_pass_on_empty_hand = 1, and a solved puzzle's own game_rounds
     * row used to stay status = 'in_progress' forever (advancePuzzleTurn()
     * only ever refreshes it, never scores it), so advanceAutomatedTurns()
     * kept auto-passing the already-solved, now-empty-handed puzzle up to
     * MAX_AUTOMATED_ACTIONS_PER_REQUEST times and returned that instead,
     * clobbering the real game_completed => true the solving play itself
     * had already produced. checkPuzzleGoal() now also flips the round to
     * 'scored' on a solve, so currentRound() throws afterward the same
     * way it already does for every other completed format, and
     * advanceAutomatedTurns() correctly finds nothing left to drive.
     */
    public function testAdvanceAutomatedTurnsDoesNotClobberASolvedPuzzlesResponse(): void
    {
        ['gameId' => $gameId, 'gamePlayerId' => $p] = $this->attempt('chain-reaction');

        $this->play($gameId, $p, $this->instanceId($gameId, 3, 'hand'), []);
        $this->play($gameId, $p, $this->instanceId($gameId, 16, 'hand'), []);
        $result = $this->play($gameId, $p, $this->instanceId($gameId, 44, 'hand'), []);
        self::assertTrue($result['game_completed']);

        $autoResult = $this->games->advanceAutomatedTurns($gameId);
        self::assertNull($autoResult, 'nothing should be left to auto-drive once a puzzle is solved');
        $this->assertGameSolved($gameId, $p);
    }

    public function testTurnItOnYourselfSolvedByTargetingConvictionItself(): void
    {
        ['gameId' => $gameId, 'gamePlayerId' => $p] = $this->attempt('turn-it-on-yourself');

        $convictionId = $this->instanceId($gameId, 6, 'hand');
        $result = $this->play($gameId, $p, $convictionId, ['target_mood_id' => $convictionId]);

        self::assertTrue($result['game_completed']);
        $this->assertGameSolved($gameId, $p);

        $stmt = $this->pdo->prepare("SELECT 1 FROM game_cards WHERE game_id = :game_id AND card_id = 16 AND zone = 'hand'");
        $stmt->execute(['game_id' => $gameId]);
        self::assertNotFalse($stmt->fetchColumn(), 'Idealism should have been drawn into hand');
    }

    public function testChainReactionSolvedByPlayingVanillaLast(): void
    {
        ['gameId' => $gameId, 'gamePlayerId' => $p] = $this->attempt('chain-reaction');

        $this->play($gameId, $p, $this->instanceId($gameId, 3, 'hand'), []); // Charity
        $this->play($gameId, $p, $this->instanceId($gameId, 16, 'hand'), []); // Idealism
        $result = $this->play($gameId, $p, $this->instanceId($gameId, 44, 'hand'), []); // Indifference

        self::assertTrue($result['game_completed']);
        $this->assertGameSolved($gameId, $p);
    }

    public function testChainReactionVanillaFirstDoesNotSolveIt(): void
    {
        ['gameId' => $gameId, 'gamePlayerId' => $p] = $this->attempt('chain-reaction');

        $this->play($gameId, $p, $this->instanceId($gameId, 44, 'hand'), []); // Indifference first -- stalls
        $this->play($gameId, $p, $this->instanceId($gameId, 3, 'hand'), []);
        $result = $this->play($gameId, $p, $this->instanceId($gameId, 16, 'hand'), []);

        self::assertFalse($result['game_completed']);
        $this->assertGameNotSolved($gameId);
    }

    public function testColorChainSolvedByFollowingBenevolenceWithADifferentColor(): void
    {
        ['gameId' => $gameId, 'gamePlayerId' => $p] = $this->attempt('color-chain');

        $this->play($gameId, $p, $this->instanceId($gameId, 2, 'hand'), []); // Benevolence (white)
        $this->play($gameId, $p, $this->instanceId($gameId, 37, 'hand'), []); // Duplicity (blue) -- satisfies the grant
        $result = $this->play($gameId, $p, $this->instanceId($gameId, 16, 'hand'), []); // Idealism (white)

        self::assertTrue($result['game_completed']);
        $this->assertGameSolved($gameId, $p);
    }

    public function testColorChainSameColorFollowUpIsIllegal(): void
    {
        ['gameId' => $gameId, 'gamePlayerId' => $p] = $this->attempt('color-chain');

        $this->play($gameId, $p, $this->instanceId($gameId, 2, 'hand'), []); // Benevolence (white)

        $this->expectException(IllegalPlayException::class);
        $this->play($gameId, $p, $this->instanceId($gameId, 16, 'hand'), []); // Idealism (white) -- shares Benevolence's own color
    }

    public function testVainEffortSolvedByPlayingVanityLast(): void
    {
        ['gameId' => $gameId, 'gamePlayerId' => $p] = $this->attempt('vain-effort');

        $this->play($gameId, $p, $this->instanceId($gameId, 3, 'hand'), []); // Charity
        $this->play($gameId, $p, $this->instanceId($gameId, 16, 'hand'), []); // Idealism
        $result = $this->play($gameId, $p, $this->instanceId($gameId, 79, 'hand'), []); // Vanity, hand now empty

        self::assertTrue($result['game_completed']);
        $this->assertGameSolved($gameId, $p);
    }

    public function testVainEffortPlayingVanityFirstDoesNotReachTenEfficiently(): void
    {
        ['gameId' => $gameId, 'gamePlayerId' => $p] = $this->attempt('vain-effort');

        // Vanity first: hand isn't empty yet, so it's only worth +1/mood
        // (itself), forcing a refresh before the other two can be played.
        $this->play($gameId, $p, $this->instanceId($gameId, 79, 'hand'), []);
        $this->play($gameId, $p, $this->instanceId($gameId, 3, 'hand'), []);
        $result = $this->play($gameId, $p, $this->instanceId($gameId, 16, 'hand'), []);

        self::assertFalse($result['game_completed']);
        $this->assertGameNotSolved($gameId);
    }

    public function testWondersChoiceSolvedByChoosingTheMajorityColor(): void
    {
        ['gameId' => $gameId, 'gamePlayerId' => $p] = $this->attempt('wonders-choice');

        // Complacency and Idealism are white (2 matches); Indifference alone is blue (1 match).
        $result = $this->play($gameId, $p, $this->instanceId($gameId, 133, 'hand'), ['color' => 'white']);

        self::assertTrue($result['game_completed']);
        $this->assertGameSolved($gameId, $p);
    }

    public function testWondersChoiceChoosingTheMinorityColorFallsShort(): void
    {
        ['gameId' => $gameId, 'gamePlayerId' => $p] = $this->attempt('wonders-choice');

        $result = $this->play($gameId, $p, $this->instanceId($gameId, 133, 'hand'), ['color' => 'blue']);

        self::assertFalse($result['game_completed']);
        $this->assertGameNotSolved($gameId);
    }

    public function testEnviousTimingSolvedByEstablishingAMoodBeforePayingEnvysCost(): void
    {
        ['gameId' => $gameId, 'gamePlayerId' => $p] = $this->attempt('envious-timing');

        $this->play($gameId, $p, $this->instanceId($gameId, 44, 'hand'), []); // Indifference, establishes a mood in play
        $indifferenceInPlayId = $this->instanceId($gameId, 44, 'in_play');
        $result = $this->play($gameId, $p, $this->instanceId($gameId, 64, 'hand'), ['discard_mood_id' => $indifferenceInPlayId]);

        self::assertTrue($result['game_completed']);
        $this->assertGameSolved($gameId, $p);
    }

    public function testEnviousTimingCannotBePlayedFirst(): void
    {
        ['gameId' => $gameId, 'gamePlayerId' => $p] = $this->attempt('envious-timing');

        $this->expectException(IllegalPlayException::class);
        $this->play($gameId, $p, $this->instanceId($gameId, 64, 'hand'), []); // Envy with an empty board -- no mood to pay its cost with
    }

    public function testValidationLoopSolvedByChainingThroughTheLowValueCards(): void
    {
        ['gameId' => $gameId, 'gamePlayerId' => $p] = $this->attempt('validation-loop');

        $this->play($gameId, $p, $this->instanceId($gameId, 16, 'hand'), []); // Idealism (0)
        $this->play($gameId, $p, $this->instanceId($gameId, 37, 'hand'), []); // Duplicity (0)
        $result = $this->play($gameId, $p, $this->instanceId($gameId, 83, 'hand'), []); // Boredom (4)

        self::assertTrue($result['game_completed']);
        $this->assertGameSolved($gameId, $p);
    }

    public function testValidationLoopBoredomFirstDoesNotSolveItEfficiently(): void
    {
        ['gameId' => $gameId, 'gamePlayerId' => $p] = $this->attempt('validation-loop');

        // Boredom (4) doesn't trigger Validation's reactive grant and has
        // no ability of its own -- playing it first stalls the turn.
        $this->play($gameId, $p, $this->instanceId($gameId, 83, 'hand'), []);
        $this->play($gameId, $p, $this->instanceId($gameId, 16, 'hand'), []);
        $result = $this->play($gameId, $p, $this->instanceId($gameId, 37, 'hand'), []);

        self::assertFalse($result['game_completed']);
        $this->assertGameNotSolved($gameId);
    }

    public function testKindredColorsSolvedByFollowingEagernessWithASharedColor(): void
    {
        ['gameId' => $gameId, 'gamePlayerId' => $p] = $this->attempt('kindred-colors');

        $this->play($gameId, $p, $this->instanceId($gameId, 114, 'hand'), []); // Eagerness (green)
        $this->play($gameId, $p, $this->instanceId($gameId, 128, 'hand'), []); // Nostalgia (green) -- satisfies the grant
        $result = $this->play($gameId, $p, $this->instanceId($gameId, 37, 'hand'), []); // Duplicity (blue)

        self::assertTrue($result['game_completed']);
        $this->assertGameSolved($gameId, $p);
    }

    public function testKindredColorsDifferentColorFollowUpIsIllegal(): void
    {
        ['gameId' => $gameId, 'gamePlayerId' => $p] = $this->attempt('kindred-colors');

        $this->play($gameId, $p, $this->instanceId($gameId, 114, 'hand'), []); // Eagerness (green)

        $this->expectException(IllegalPlayException::class);
        $this->play($gameId, $p, $this->instanceId($gameId, 37, 'hand'), []); // Duplicity (blue) -- doesn't share Eagerness's own color
    }

    public function testTheLesserSacrificeSolvedByTargetingConvictionItself(): void
    {
        ['gameId' => $gameId, 'gamePlayerId' => $p] = $this->attempt('the-lesser-sacrifice');

        $convictionId = $this->instanceId($gameId, 6, 'hand');
        $result = $this->play($gameId, $p, $convictionId, ['target_mood_id' => $convictionId]);

        self::assertTrue($result['game_completed']);
        $this->assertGameSolved($gameId, $p);
    }

    public function testTheLesserSacrificeTargetingAHigherValueMoodFallsShort(): void
    {
        ['gameId' => $gameId, 'gamePlayerId' => $p] = $this->attempt('the-lesser-sacrifice');

        $boredomInPlayId = $this->instanceId($gameId, 83, 'in_play');
        $result = $this->play($gameId, $p, $this->instanceId($gameId, 6, 'hand'), ['target_mood_id' => $boredomInPlayId]);

        self::assertFalse($result['game_completed']);
        $this->assertGameNotSolved($gameId);
    }
}
