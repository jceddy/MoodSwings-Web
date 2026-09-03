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
 * Issue #419's own Tactical Bot tier -- exercises the async job wiring
 * end to end against a real database: does a Tactical Bot's own turn
 * launch a background job instead of playing inline, does polling again
 * avoid launching a duplicate, does a stale/crashed job fall back to the
 * ordinary heuristic bot, does getState() expose the "bot is thinking"
 * indicator, and does the background process's own entry point
 * (runTacticalBotSearchJob(), called directly here rather than via a real
 * spawned subprocess -- see bin/run_bot_search.php for the thin CLI
 * wrapper around it) actually apply its chosen action and mark the job
 * done. MoodSwings\Tests\Bot\SearchBotPlayerServiceTest/
 * LegalChoiceEnumeratorTest/DeterminizerTest already cover the search
 * engine's own decision quality in isolation, so these focus purely on
 * the request-lifecycle wiring.
 */
final class BotSearchIntegrationTest extends TestCase
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
        $pdo->exec('TRUNCATE TABLE bot_search_jobs');
        $pdo->exec('TRUNCATE TABLE game_events');
        $pdo->exec('TRUNCATE TABLE game_initial_card_passes');
        $pdo->exec('TRUNCATE TABLE game_team_decisions');
        $pdo->exec('TRUNCATE TABLE game_pending_decisions');
        $pdo->exec('TRUNCATE TABLE game_pending_decision_batches');
        $pdo->exec('TRUNCATE TABLE game_round_scores');
        $pdo->exec('TRUNCATE TABLE game_cards');
        $pdo->exec('TRUNCATE TABLE game_rounds');
        $pdo->exec('TRUNCATE TABLE game_players');
        $pdo->exec('TRUNCATE TABLE games');
        $pdo->exec('TRUNCATE TABLE user_lifetime_stats');
        $pdo->exec('TRUNCATE TABLE card_stats');
        $pdo->exec('TRUNCATE TABLE user_decklists');
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
        // A near-zero search budget -- these tests exercise the JOB
        // WIRING (launch/dedup/stale-fallback/apply/mark-done), not
        // search decision quality, so there's no reason to wait out
        // anything beyond a handful of guaranteed-cheap rollouts.
        // spawnBotSearchProcesses is off so launching a job doesn't ALSO
        // fork a real background `php bin/run_bot_search.php` process --
        // that process would inherit this test's own env (including the
        // test-DB connection) and race these tests' own direct calls to
        // runTacticalBotSearchJob()/backdating against the very same
        // bot_search_jobs row.
        $this->games = new GameService(
            new BoardStateRepository($registry),
            new MoodPlayService($registry),
            new RoundScorer(),
            $userDecklists,
            new ReplayStateBuilder($registry),
            botSearchTimeBudgetSeconds: 0,
            spawnBotSearchProcesses: false,
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

    private function insertTacticalBotUser(string $username): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO users (username, email, password_hash, email_verified_at, is_bot, uses_tactical_ai)
             VALUES (:username, :email, 'hash', NOW(), 1, 1)"
        );
        $stmt->execute(['username' => $username, 'email' => "{$username}@example.com"]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Deals the game and hands off to advanceAutomatedTurns() exactly the
     * way public/index.php does after every mutating route -- neither
     * startGame() nor pass()/playMood() ever call it themselves (see
     * advanceAutomatedTurns()'s own docblock: it's a wrapper CALLED
     * AFTER those, not threaded into their own internals). Whichever seat
     * resolveFirstPlayerId() happens to pick goes first at random, so the
     * human's own turn is explicitly passed first when necessary to make
     * every test here deterministic: by the time this returns, the
     * Tactical Bot's own turn has always just been handed to
     * advanceTacticalBotSearch(), regardless of who went first.
     *
     * @return array{human: int, bot: int, gameId: int}
     */
    private function createTacticalBotGame(): array
    {
        $human = $this->insertUser('bs-human-' . uniqid());
        $bot = $this->insertTacticalBotUser('bs-bot-' . uniqid());
        $gameId = $this->games->createGame($human, [$human, $bot], format: 'duel', deckType: 'structure');
        $this->games->startGame($gameId);

        $humanPlayerId = $this->games->gamePlayerIdFor($gameId, $human);
        $currentTurnGamePlayerId = (int) $this->pdo
            ->query("SELECT current_turn_game_player_id FROM game_rounds WHERE game_id = {$gameId} ORDER BY round_number DESC LIMIT 1")
            ->fetchColumn();
        if ($currentTurnGamePlayerId === $humanPlayerId) {
            $this->games->pass($gameId, $humanPlayerId);
        }

        $this->games->advanceAutomatedTurns($gameId);

        return ['human' => $human, 'bot' => $bot, 'gameId' => $gameId];
    }

    public function testAdvanceAutomatedTurnsLaunchesABackgroundJobInsteadOfPlayingInline(): void
    {
        ['bot' => $bot, 'gameId' => $gameId] = $this->createTacticalBotGame();
        $botPlayerId = $this->games->gamePlayerIdFor($gameId, $bot);

        $stmt = $this->pdo->prepare('SELECT status FROM bot_search_jobs WHERE game_player_id = :id');
        $stmt->execute(['id' => $botPlayerId]);
        $jobs = $stmt->fetchAll(PDO::FETCH_COLUMN);

        self::assertSame(['running'], $jobs, 'the bot\'s own turn must hand off to exactly one background job, not play inline');
    }

    public function testAdvanceAutomatedTurnsDoesNotLaunchADuplicateJobWhileOneIsAlreadyRunning(): void
    {
        ['bot' => $bot, 'gameId' => $gameId] = $this->createTacticalBotGame();
        $botPlayerId = $this->games->gamePlayerIdFor($gameId, $bot);

        // Poll again (exactly what a later HTTP request's own
        // advanceAutomatedTurns() call would do) -- must NOT launch a
        // second job for the same still-open turn.
        $this->games->advanceAutomatedTurns($gameId);

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM bot_search_jobs WHERE game_player_id = :id');
        $stmt->execute(['id' => $botPlayerId]);
        self::assertSame(1, (int) $stmt->fetchColumn());
    }

    public function testGetStateExposesBotThinkingWhileAJobIsRunning(): void
    {
        ['human' => $human, 'bot' => $bot, 'gameId' => $gameId] = $this->createTacticalBotGame();
        $botPlayerId = $this->games->gamePlayerIdFor($gameId, $bot);

        $state = $this->games->getState($gameId, $human);

        self::assertNotNull($state['bot_thinking']);
        self::assertSame($botPlayerId, $state['bot_thinking']['game_player_id']);
    }

    public function testStaleJobFallsBackToTheHeuristicBotAndIsMarkedFailed(): void
    {
        ['human' => $human, 'gameId' => $gameId] = $this->createTacticalBotGame();

        // Back-date the job launched during setup past its own budget (0)
        // plus the stale grace period, simulating a crashed background
        // process (e.g. a dev-server restart mid-search).
        $this->pdo->exec('UPDATE bot_search_jobs SET started_at = started_at - INTERVAL 1 HOUR');

        $this->games->advanceAutomatedTurns($gameId);

        $jobStmt = $this->pdo->query('SELECT status FROM bot_search_jobs ORDER BY id DESC LIMIT 1');
        self::assertSame('failed', $jobStmt->fetchColumn(), 'a stale job must be marked failed, not left running forever');

        $state = $this->games->getState($gameId, $human);
        self::assertNull($state['bot_thinking'], 'no job is genuinely in flight once the stale one has been given up on and a fresh play/pass applied inline');
    }

    public function testRunTacticalBotSearchJobAppliesItsActionAndMarksTheJobDone(): void
    {
        ['bot' => $bot, 'gameId' => $gameId] = $this->createTacticalBotGame();
        $botPlayerId = $this->games->gamePlayerIdFor($gameId, $bot);

        $jobStmt = $this->pdo->prepare('SELECT id FROM bot_search_jobs WHERE game_player_id = :id ORDER BY id DESC LIMIT 1');
        $jobStmt->execute(['id' => $botPlayerId]);
        $jobId = (int) $jobStmt->fetchColumn();
        self::assertGreaterThan(0, $jobId, 'setup must have already launched a job for this seat to run');

        $lastEventIdBefore = (int) $this->pdo->query('SELECT COALESCE(MAX(id), 0) FROM game_events')->fetchColumn();

        // Exactly what bin/run_bot_search.php does, minus actually
        // spawning a separate OS process for it.
        $this->games->runTacticalBotSearchJob($jobId);

        $statusStmt = $this->pdo->prepare('SELECT status FROM bot_search_jobs WHERE id = :id');
        $statusStmt->execute(['id' => $jobId]);
        self::assertSame('done', $statusStmt->fetchColumn());

        // Both playMood() and pass() always log a game_events row for the
        // acting seat -- checking for that (rather than e.g. asserting the
        // turn moved off the bot, or that a card left their hand) is the
        // one signal that holds regardless of which action the search
        // chose: a played card might come from the discard pile rather
        // than the hand (Angst/Harmony/Grief/Melancholy-style grants), and
        // a play that also grants itself another play this same turn
        // legitimately leaves current_turn_game_player_id unchanged.
        $eventStmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM game_events WHERE id > :last_id AND acting_game_player_id = :bot_id'
        );
        $eventStmt->execute(['last_id' => $lastEventIdBefore, 'bot_id' => $botPlayerId]);
        self::assertGreaterThan(0, (int) $eventStmt->fetchColumn(), 'the bot\'s own turn must have actually been taken, not left untouched');
    }
}
