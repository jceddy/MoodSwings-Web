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
            spawnAutomatedTurnRecheckProcesses: false,
        );
    }

    /**
     * A fresh GameService instance carrying its own explicit
     * botSearchTimeBudgetSeconds override -- unlike $this->games'
     * own fixed 0 (see setUp()'s own docblock, tuned for job-wiring
     * tests that don't care what the budget actually IS), the halved-
     * budget tests below need a genuinely non-zero starting value to
     * tell "halved" apart from "not halved" at all.
     */
    private function gamesWithBudget(int $seconds): GameService
    {
        $registry = DefaultEffectRegistry::build();

        return new GameService(
            new BoardStateRepository($registry),
            new MoodPlayService($registry),
            new RoundScorer(),
            new UserDecklistService(
                new UserDecklistRepository(),
                new FriendshipService(new UserRepository(), new FriendshipRepository()),
            ),
            new ReplayStateBuilder($registry),
            botSearchTimeBudgetSeconds: $seconds,
            spawnBotSearchProcesses: false,
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

    private function insertTacticalBotUser(string $username): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO users (username, email, password_hash, email_verified_at, is_bot, uses_tactical_ai)
             VALUES (:username, :email, 'hash', NOW(), 1, 1)"
        );
        $stmt->execute(['username' => $username, 'email' => "{$username}@example.com"]);

        return (int) $this->pdo->lastInsertId();
    }

    /** is_bot without uses_tactical_ai -- the ordinary heuristic tier, never handed off to advanceTacticalBotSearch()'s own job machinery. */
    private function insertHeuristicBotUser(string $username): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO users (username, email, password_hash, email_verified_at, is_bot, uses_tactical_ai)
             VALUES (:username, :email, 'hash', NOW(), 1, 0)"
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
    private function createTacticalBotGame(bool $diagnosticMode = false): array
    {
        $human = $this->insertUser('bs-human-' . uniqid());
        $bot = $this->insertTacticalBotUser('bs-bot-' . uniqid());
        $gameId = $this->games->createGame($human, [$human, $bot], format: 'duel', deckType: 'structure', diagnosticMode: $diagnosticMode);
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

    /**
     * game_cards.id (the engine's own "instance id", everywhere a
     * `card_id` parameter/return value actually means -- see
     * BoardStateRepository::load()'s own $catalogCardIdFor) is an
     * auto-increment surrogate, NOT the catalog card_id column
     * insertGameCard() above takes -- so a test asserting a SPECIFIC
     * played/checkpointed card must look this up rather than assuming
     * the catalog id it inserted with is what comes back out.
     */
    private function gameCardInstanceId(int $gameId, int $catalogCardId): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM game_cards WHERE game_id = :game_id AND card_id = :card_id');
        $stmt->execute(['game_id' => $gameId, 'card_id' => $catalogCardId]);

        return (int) $stmt->fetchColumn();
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

    /**
     * Builds a game directly via raw inserts (bypassing createGame()/
     * startGame()'s own real deck-building/dealing entirely) so the
     * Tactical Bot's own hand can be pinned to an EXACT set of card ids
     * -- both cards used below (55 = Apathy, 7 = Courage) have no
     * hasToPlay precondition at all, so either is always legally
     * playable regardless of board state, guaranteeing the search job
     * actually gets launched rather than short-circuiting through the
     * "no legal play" fast path.
     *
     * @param int[] $botHandCardIds
     * @return array{gameId: int, botPlayerId: int}
     */
    private function createRawTacticalBotGame(GameService $games, array $botHandCardIds, bool $diagnosticMode = false): array
    {
        $human = $this->insertUser('bs-raw-human-' . uniqid());
        $bot = $this->insertTacticalBotUser('bs-raw-bot-' . uniqid());

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed, diagnostic_mode) VALUES ('standard', 'in_progress', :created_by, 3, :diagnostic_mode)"
        );
        $stmt->execute(['created_by' => $human, 'diagnostic_mode' => $diagnosticMode ? 1 : 0]);
        $gameId = (int) $this->pdo->lastInsertId();

        $this->insertGamePlayer($gameId, $human, 0);
        $botPlayerId = $this->insertGamePlayer($gameId, $bot, 1);

        foreach ($botHandCardIds as $cardId) {
            $this->insertGameCard($gameId, $cardId, 'hand', $botPlayerId);
        }
        $this->insertGameRound($gameId, 1, $botPlayerId, $botPlayerId, 1);

        $games->advanceAutomatedTurns($gameId);

        return ['gameId' => $gameId, 'botPlayerId' => $botPlayerId];
    }

    /**
     * Same shape as createRawTacticalBotGame(), but for the plain
     * heuristic tier (is_bot without uses_tactical_ai) -- exercises
     * advanceAutomatedTurns()'s own ordinary bot branch (never handed off
     * to the Tactical Bot's job machinery at all) for
     * logHeuristicBotReasoning()'s own tests below.
     *
     * @param int[] $botHandCardIds
     * @return array{gameId: int, botPlayerId: int}
     */
    private function createRawHeuristicBotGame(array $botHandCardIds, bool $diagnosticMode): array
    {
        $human = $this->insertUser('bs-raw-human-' . uniqid());
        $bot = $this->insertHeuristicBotUser('bs-raw-heur-bot-' . uniqid());

        // The human's own hand is always empty here (this helper only
        // ever deals the BOT's own hand) -- auto_pass_on_empty_hand
        // defaults to on (migration 0096), which would otherwise
        // auto-pass the human through round after round on its own,
        // cascading advanceAutomatedTurns() well past this one bot
        // decision -- fine for a test that only checks the RAW
        // game_events row this call produces, but it also means the
        // human racks up their own later turn_passed rows, moving
        // viewerOwnLastTurnEventId()'s own boundary past this decision
        // for anything checking tacticalBotReasoningSince() instead.
        // Turning it off keeps this helper to exactly the one bot
        // decision its own caller asked for.
        $this->pdo->prepare('UPDATE users SET auto_pass_on_empty_hand = 0 WHERE id = :id')->execute(['id' => $human]);

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed, diagnostic_mode) VALUES ('standard', 'in_progress', :created_by, 3, :diagnostic_mode)"
        );
        $stmt->execute(['created_by' => $human, 'diagnostic_mode' => $diagnosticMode ? 1 : 0]);
        $gameId = (int) $this->pdo->lastInsertId();

        $this->insertGamePlayer($gameId, $human, 0);
        $botPlayerId = $this->insertGamePlayer($gameId, $bot, 1);

        foreach ($botHandCardIds as $cardId) {
            $this->insertGameCard($gameId, $cardId, 'hand', $botPlayerId);
        }
        $this->insertGameRound($gameId, 1, $botPlayerId, $botPlayerId, 1);

        $this->games->advanceAutomatedTurns($gameId);

        return ['gameId' => $gameId, 'botPlayerId' => $botPlayerId];
    }

    /**
     * Reported live: "when a bot only has one card in hand, cut its max
     * thinking time in half." A single legal card leaves search with no
     * rival card to weigh it against, so launchTacticalBotSearchJob()
     * now stores half the usual time_budget_seconds for a one-card hand.
     */
    public function testLaunchesWithHalfTheTimeBudgetWhenTheBotHasExactlyOneCardInHand(): void
    {
        $games = $this->gamesWithBudget(20);
        ['botPlayerId' => $botPlayerId] = $this->createRawTacticalBotGame($games, [55]); // Apathy alone

        $stmt = $this->pdo->prepare('SELECT time_budget_seconds FROM bot_search_jobs WHERE game_player_id = :id');
        $stmt->execute(['id' => $botPlayerId]);
        self::assertSame(10, (int) $stmt->fetchColumn());
    }

    /** Control for the halving test above -- a normal, multi-card hand keeps the full budget. */
    public function testLaunchesWithTheFullTimeBudgetWhenTheBotHasMoreThanOneCardInHand(): void
    {
        $games = $this->gamesWithBudget(20);
        ['botPlayerId' => $botPlayerId] = $this->createRawTacticalBotGame($games, [55, 7]); // Apathy, Courage

        $stmt = $this->pdo->prepare('SELECT time_budget_seconds FROM bot_search_jobs WHERE game_player_id = :id');
        $stmt->execute(['id' => $botPlayerId]);
        self::assertSame(20, (int) $stmt->fetchColumn());
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

    /**
     * Reported live: a Tactical Bot took its full time budget on every
     * turn, even a genuinely empty-hand one with no legal play at all,
     * because advanceAutomatedTurns()'s own tactical-bot branch had no
     * upfront "does this seat even have a legal play" check (unlike the
     * ordinary heuristic-bot branch and the auto-pass branch, which both
     * already had one) -- it always launched the background search job
     * machinery regardless, which only ever short-circuited correctly
     * INSIDE that job, after the job had already been launched and its
     * full budget waited out. This drains ONLY the bot's own hand plus
     * the shared discard pile (candidatePlayCardIds() is hand plus
     * discard) into the in-play zone -- nothing left anywhere
     * isPlayable() could approve for this seat specifically -- and
     * asserts NO bot_search_jobs row is ever created, with the turn
     * passed immediately and automatically instead. Deliberately leaves
     * the HUMAN's own hand untouched: draining it too would make the
     * human ALSO auto-pass (issue #96's own default-on preference), which
     * would end the round outright and deal a fresh hand for the next one
     * -- masking the very bug this asserts against instead of proving it
     * fixed.
     */
    public function testAdvanceAutomatedTurnsPassesImmediatelyWithNoBackgroundJobWhenTheBotHasNoLegalPlay(): void
    {
        $human = $this->insertUser('bs-human-' . uniqid());
        $bot = $this->insertTacticalBotUser('bs-bot-' . uniqid());
        $gameId = $this->games->createGame($human, [$human, $bot], format: 'duel', deckType: 'structure');
        $this->games->startGame($gameId);

        $humanPlayerId = $this->games->gamePlayerIdFor($gameId, $human);
        $botPlayerId = $this->games->gamePlayerIdFor($gameId, $bot);
        $currentTurnGamePlayerId = (int) $this->pdo
            ->query("SELECT current_turn_game_player_id FROM game_rounds WHERE game_id = {$gameId} ORDER BY round_number DESC LIMIT 1")
            ->fetchColumn();
        if ($currentTurnGamePlayerId === $humanPlayerId) {
            $this->games->pass($gameId, $humanPlayerId);
        }

        // Empties candidatePlayCardIds()'s own two sources for the BOT
        // specifically (its own hand, plus the whole shared discard pile)
        // by moving everything sitting in either into the in-play zone
        // instead -- isPlayable() never even gets a candidate to
        // consider. The human's own hand is left alone -- see this test's
        // own docblock for why.
        $this->pdo->exec(
            "UPDATE game_cards SET zone = 'in_play'
             WHERE game_id = {$gameId} AND (zone = 'discard' OR (zone = 'hand' AND owner_game_player_id = {$botPlayerId}))"
        );

        $this->games->advanceAutomatedTurns($gameId);

        $jobCountStmt = $this->pdo->prepare('SELECT COUNT(*) FROM bot_search_jobs WHERE game_player_id = :id');
        $jobCountStmt->execute(['id' => $botPlayerId]);
        self::assertSame(
            0,
            (int) $jobCountStmt->fetchColumn(),
            'a bot with no legal play at all must never have a background search job launched for it'
        );

        $eventStmt = $this->pdo->prepare(
            "SELECT details FROM game_events WHERE game_id = :game_id AND acting_game_player_id = :bot_id AND event_type = 'turn_passed' ORDER BY id DESC LIMIT 1"
        );
        $eventStmt->execute(['game_id' => $gameId, 'bot_id' => $botPlayerId]);
        $eventDetails = $eventStmt->fetchColumn();
        self::assertNotFalse($eventDetails, 'the bot must have passed automatically instead of being left waiting on a job');
        self::assertSame(['automated' => true], json_decode((string) $eventDetails, true));
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

    // -- Heartbeat + partial-search checkpoint (migration 0283) -------------

    /**
     * Reported live: "based on the results I'm seeing when I test this
     * process must be crashing basically all the time" -- heartbeat_at is
     * stamped immediately on boot, before the search itself even starts,
     * so a stale job whose heartbeat_at is STILL null tells the
     * difference between "the process never even got PHP running" and
     * "it started and died partway through" (see migration 0283's own
     * docblock). A near-zero time budget (this suite's usual setup) still
     * means the process itself genuinely ran, so heartbeat_at must always
     * end up set by the time runTacticalBotSearchJob() returns.
     */
    public function testRunTacticalBotSearchJobRecordsAHeartbeatImmediately(): void
    {
        ['bot' => $bot, 'gameId' => $gameId] = $this->createTacticalBotGame();
        $botPlayerId = $this->games->gamePlayerIdFor($gameId, $bot);

        $jobStmt = $this->pdo->prepare('SELECT id, heartbeat_at FROM bot_search_jobs WHERE game_player_id = :id ORDER BY id DESC LIMIT 1');
        $jobStmt->execute(['id' => $botPlayerId]);
        $jobRow = $jobStmt->fetch();
        self::assertNull($jobRow['heartbeat_at'], 'a freshly launched job has not run yet -- nothing should have stamped a heartbeat before runTacticalBotSearchJob() itself is called');

        $this->games->runTacticalBotSearchJob((int) $jobRow['id']);

        $heartbeatStmt = $this->pdo->prepare('SELECT heartbeat_at FROM bot_search_jobs WHERE id = :id');
        $heartbeatStmt->execute(['id' => $jobRow['id']]);
        self::assertNotNull($heartbeatStmt->fetchColumn());
    }

    /**
     * Reported live: "is there any way that we could have the tactical
     * bot use any results found so far from a partial search when it
     * gets to time instead of completely abandoning any information" --
     * a budget comfortably past SearchBotPlayerService::CHECKPOINT_INTERVAL_SECONDS
     * (1.0) guarantees the search loop's own periodic $onProgress
     * callback actually lands at least once, writing a real
     * best_action_card_id/best_action_choices/best_action_recorded_at
     * snapshot into this exact job row -- end-to-end through GameService,
     * not just SearchBotPlayerServiceTest's own unit-level coverage of
     * $onProgress itself.
     */
    public function testRunTacticalBotSearchJobRecordsAPeriodicBestActionCheckpointForALongerSearch(): void
    {
        $games = $this->gamesWithBudget(2);
        ['gameId' => $gameId, 'botPlayerId' => $botPlayerId] = $this->createRawTacticalBotGame($games, [55, 7]); // Apathy, Courage -- both always legally playable
        $instanceIds = [$this->gameCardInstanceId($gameId, 55), $this->gameCardInstanceId($gameId, 7)];

        $jobStmt = $this->pdo->prepare('SELECT id FROM bot_search_jobs WHERE game_player_id = :id ORDER BY id DESC LIMIT 1');
        $jobStmt->execute(['id' => $botPlayerId]);
        $jobId = (int) $jobStmt->fetchColumn();

        $games->runTacticalBotSearchJob($jobId);

        $rowStmt = $this->pdo->prepare('SELECT best_action_card_id, best_action_recorded_at FROM bot_search_jobs WHERE id = :id');
        $rowStmt->execute(['id' => $jobId]);
        $row = $rowStmt->fetch();
        self::assertNotNull($row['best_action_recorded_at'], 'a 2-second search over two real candidates must have checkpointed at least once');
        if ($row['best_action_card_id'] !== null) {
            self::assertContains((int) $row['best_action_card_id'], $instanceIds);
        }
    }

    /**
     * The other half of the same live report as the checkpoint test
     * above: a stale/crashed job whose own last checkpoint DID land
     * (best_action_recorded_at non-null) must play THAT action instead of
     * discarding it for the plain heuristic bot -- see
     * playRecoveredPartialSearchResult()'s own docblock. Manually written
     * here (rather than waiting out a real checkpoint) for a fast,
     * deterministic test of the recovery branch itself.
     */
    public function testAdvanceTacticalBotSearchAppliesARecoveredBestActionCheckpointInsteadOfTheHeuristicFallback(): void
    {
        ['gameId' => $gameId, 'botPlayerId' => $botPlayerId] = $this->createRawTacticalBotGame($this->games, [55, 7], diagnosticMode: true);
        $apathyInstanceId = $this->gameCardInstanceId($gameId, 55);

        $jobStmt = $this->pdo->prepare('SELECT id FROM bot_search_jobs WHERE game_player_id = :id ORDER BY id DESC LIMIT 1');
        $jobStmt->execute(['id' => $botPlayerId]);
        $jobId = (int) $jobStmt->fetchColumn();

        $this->pdo->prepare(
            "UPDATE bot_search_jobs SET best_action_card_id = :card_id, best_action_choices = '[]', best_action_recorded_at = NOW(),
                 started_at = started_at - INTERVAL 1 HOUR WHERE id = :id"
        )->execute(['id' => $jobId, 'card_id' => $apathyInstanceId]);

        $this->games->advanceAutomatedTurns($gameId);

        $statusStmt = $this->pdo->prepare('SELECT status FROM bot_search_jobs WHERE id = :id');
        $statusStmt->execute(['id' => $jobId]);
        self::assertSame('failed', $statusStmt->fetchColumn(), 'still a stale job -- recovering its checkpoint does not change that it was never actually finished');

        $playedStmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM game_events WHERE game_id = :game_id AND acting_game_player_id = :bot_id AND event_type = 'mood_played' AND card_id = :card_id"
        );
        $playedStmt->execute(['game_id' => $gameId, 'bot_id' => $botPlayerId, 'card_id' => $apathyInstanceId]);
        self::assertSame(1, (int) $playedStmt->fetchColumn(), 'the checkpointed card (Apathy) must actually have been played, not Courage or a pass');

        $reasoningStmt = $this->pdo->prepare(
            "SELECT details FROM game_events WHERE game_id = :game_id AND acting_game_player_id = :bot_id AND event_type = 'tactical_bot_reasoning'"
        );
        $reasoningStmt->execute(['game_id' => $gameId, 'bot_id' => $botPlayerId]);
        $details = json_decode((string) $reasoningStmt->fetchColumn(), true);
        self::assertNotNull($details, 'a recovered checkpoint still logs a tactical_bot_reasoning row when diagnostic mode is on');
        self::assertTrue($details['recovered_from_stalled_search'], 'must be flagged as recovered, not a completed search');
    }

    /**
     * The genuine total-loss case -- a stale job with NO checkpoint ever
     * recorded (best_action_recorded_at still null) has nothing to
     * recover, so it still falls back to the plain heuristic bot exactly
     * as before -- but that fallback now ALSO logs a
     * 'heuristic_bot_reasoning' row (playViaHeuristicBotFallback()'s own
     * new diagnostic-mode logging), so this turn is no longer a total
     * blank in the reasoning dialog either, just a coarser kind of
     * reasoning than a completed search would have logged.
     */
    public function testAdvanceTacticalBotSearchLogsHeuristicReasoningWhenStaleWithNoCheckpointAtAll(): void
    {
        ['gameId' => $gameId, 'botPlayerId' => $botPlayerId] = $this->createRawTacticalBotGame($this->games, [55, 7], diagnosticMode: true);

        $this->pdo->exec('UPDATE bot_search_jobs SET started_at = started_at - INTERVAL 1 HOUR');

        $this->games->advanceAutomatedTurns($gameId);

        $statusStmt = $this->pdo->query('SELECT status FROM bot_search_jobs ORDER BY id DESC LIMIT 1');
        self::assertSame('failed', $statusStmt->fetchColumn());

        $tacticalReasoningStmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM game_events WHERE game_id = :game_id AND acting_game_player_id = :bot_id AND event_type = 'tactical_bot_reasoning'"
        );
        $tacticalReasoningStmt->execute(['game_id' => $gameId, 'bot_id' => $botPlayerId]);
        self::assertSame(0, (int) $tacticalReasoningStmt->fetchColumn(), 'no checkpoint existed, so there is genuinely no tactical reasoning to log');

        $heuristicReasoningStmt = $this->pdo->prepare(
            "SELECT details FROM game_events WHERE game_id = :game_id AND acting_game_player_id = :bot_id AND event_type = 'heuristic_bot_reasoning'"
        );
        $heuristicReasoningStmt->execute(['game_id' => $gameId, 'bot_id' => $botPlayerId]);
        $details = json_decode((string) $heuristicReasoningStmt->fetchColumn(), true);
        self::assertNotNull($details, 'the heuristic fallback itself must still log its own (coarser) reasoning when diagnostic mode is on');
        self::assertContains($details['choice_policy_path'], ['bespoke_rule', 'generic_resolver']);
    }

    // -- Heuristic bot reasoning (Part A) ------------------------------------

    /**
     * Reported live: "could we add some kind of reasoning text for the
     * default bots? like if they're using a specific card override rule
     * or something like that when making their decisions?" -- Apathy (55)
     * has no bespoke branch of its own in BotPlayerService::
     * buildBaseChoicesForCard(), so it falls through to the generic
     * resolver -- exactly the "or even just like if they are randomly
     * choosing something or choosing a safe target by default" half of
     * that same report.
     */
    public function testAdvanceAutomatedTurnsLogsHeuristicBotReasoningWhenDiagnosticModeIsOn(): void
    {
        ['gameId' => $gameId, 'botPlayerId' => $botPlayerId] = $this->createRawHeuristicBotGame([55], diagnosticMode: true);
        $apathyInstanceId = $this->gameCardInstanceId($gameId, 55);

        $stmt = $this->pdo->prepare(
            "SELECT details FROM game_events WHERE game_id = :game_id AND acting_game_player_id = :bot_id AND event_type = 'heuristic_bot_reasoning' AND card_id = :card_id"
        );
        $stmt->execute(['game_id' => $gameId, 'bot_id' => $botPlayerId, 'card_id' => $apathyInstanceId]);
        $details = json_decode((string) $stmt->fetchColumn(), true);

        self::assertNotNull($details, 'diagnostic mode must log the ordinary heuristic bot\'s own reasoning too, not just the Tactical Bot\'s');
        self::assertSame('generic_resolver', $details['choice_policy_path']);
    }

    /**
     * Reported live: with diagnostic mode on, a long chain of Creativity
     * repeatedly copying an in-play Validation (each copy retriggers
     * Validation's own "play another 0/1-value mood, get another extra
     * play" reaction -- a legitimate, correctly-terminating combo, not an
     * engine bug) showed up in "View log"/"Recent plays" as a wall of
     * bare, detail-free "BotSage played Creativity" lines with none of
     * the usual "from hand"/grant wording, indistinguishable from a
     * genuinely stuck game and prompting an unneeded Resign. Root cause:
     * 'heuristic_bot_reasoning' (this test's own event, logged for EVERY
     * action the bot even just considers, purely for the dedicated "Bot
     * reasoning" dialog) was never excluded from fullEventLog()/
     * recentEvents(), so it fell through describeEvent()'s unhandled-
     * event-type default arm -- same bug class as closed_team_leader_decided/
     * chaos_draft_effect_attached before it, per that arm's own docblock.
     * Mirrors round_grants_computed's own pre-existing exclusion from
     * both feeds (GameService::INTERNAL_ONLY_EVENT_TYPES_SQL).
     */
    public function testFullEventLogAndRecentEventsExcludeHeuristicBotReasoning(): void
    {
        ['gameId' => $gameId, 'botPlayerId' => $botPlayerId] = $this->createRawHeuristicBotGame([55], diagnosticMode: true);

        $eventTypes = array_column($this->games->fullEventLog($gameId), 'event_type');
        self::assertNotContains('heuristic_bot_reasoning', $eventTypes, 'the human-facing full log must never leak diagnostic reasoning bookkeeping');

        $humanUserId = (int) $this->pdo
            ->query("SELECT user_id FROM game_players WHERE game_id = {$gameId} AND id != {$botPlayerId}")
            ->fetchColumn();
        $recentEvents = $this->games->getState($gameId, $humanUserId)['recent_events'];

        self::assertCount(1, $recentEvents, '"Recent plays" must show exactly the one real play, not a duplicate bare reasoning entry alongside it');
        self::assertStringContainsString('from hand', $recentEvents[0]['description'], 'the surviving entry must be the real, fully-described play, not the bare reasoning one');
    }

    /**
     * Reported live: a real heuristic-fallback play (not a pass) read as
     * "passed" in the dialog -- exactly this instance-id-vs-catalog-id
     * mismatch (see testTacticalBotReasoningSinceReturnsCatalogIdsNotInstanceIds()'s
     * own docblock), just for a 'heuristic'-source entry instead of a
     * 'tactical' one.
     */
    public function testTacticalBotReasoningSinceReturnsCatalogIdsNotInstanceIdsForAHeuristicEntry(): void
    {
        ['gameId' => $gameId, 'botPlayerId' => $botPlayerId] = $this->createRawHeuristicBotGame([55], diagnosticMode: true); // Apathy alone -- always legal, so the heuristic bot definitely plays it, never passes
        $apathyInstanceId = $this->gameCardInstanceId($gameId, 55);
        self::assertNotSame(55, $apathyInstanceId, 'this test only proves anything if the instance id genuinely differs from the catalog id it stands for');

        $humanUserId = (int) $this->pdo
            ->query("SELECT user_id FROM game_players WHERE game_id = {$gameId} AND id != {$botPlayerId}")
            ->fetchColumn();

        $reasoning = $this->games->tacticalBotReasoningSince($gameId, $humanUserId);

        self::assertCount(1, $reasoning);
        self::assertSame(55, $reasoning[0]['card_id'], 'must be the catalog id (what the frontend\'s catalog map is keyed by), not the per-game instance id');
    }

    /** The exact same turn, but without diagnostic mode -- no reasoning event should exist at all, same convention as the Tactical Bot's own logTacticalBotReasoning(). */
    public function testAdvanceAutomatedTurnsDoesNotLogHeuristicBotReasoningWhenDiagnosticModeIsOff(): void
    {
        ['gameId' => $gameId, 'botPlayerId' => $botPlayerId] = $this->createRawHeuristicBotGame([55], diagnosticMode: false);

        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM game_events WHERE game_id = :game_id AND acting_game_player_id = :bot_id AND event_type = 'heuristic_bot_reasoning'"
        );
        $stmt->execute(['game_id' => $gameId, 'bot_id' => $botPlayerId]);
        self::assertSame(0, (int) $stmt->fetchColumn());
    }

    // -- Diagnostic mode ----------------------------------------------------

    /**
     * Reported live: "add a 'diagnostic mode' checkbox when creating a
     * game including one or more tactical bot(s)." createGame()'s own
     * $diagnosticMode only actually takes effect once $userIds includes
     * at least one Tactical Bot.
     */
    public function testCreateGameStoresDiagnosticModeWhenATacticalBotIsSeated(): void
    {
        $human = $this->insertUser('diag-human1');
        $bot = $this->insertTacticalBotUser('diag-bot1');

        $gameId = $this->games->createGame($human, [$human, $bot], format: 'duel', deckType: 'structure', diagnosticMode: true);

        $stmt = $this->pdo->prepare('SELECT diagnostic_mode FROM games WHERE id = :id');
        $stmt->execute(['id' => $gameId]);
        self::assertSame(1, (int) $stmt->fetchColumn());
    }

    /** Silently ignored (not an error), the same "harmless no-op outside its own narrow scope" convention every other creation-time opt-in here already follows. */
    public function testCreateGameIgnoresDiagnosticModeWithoutATacticalBotSeated(): void
    {
        $human = $this->insertUser('diag-human2');
        $ordinaryBot = $this->insertUser('diag-bot2'); // is_bot defaults to 0 here -- a plain human-shaped row is fine, this test never seats it as a bot

        $gameId = $this->games->createGame($human, [$human, $ordinaryBot], format: 'duel', deckType: 'structure', diagnosticMode: true);

        $stmt = $this->pdo->prepare('SELECT diagnostic_mode FROM games WHERE id = :id');
        $stmt->execute(['id' => $gameId]);
        self::assertSame(0, (int) $stmt->fetchColumn());
    }

    /**
     * Reported live: "a button to show the 'reasoning' behind every play
     * the bot has made... the heuristics involved, the play options
     * considered, and the relative scoring assigned to those considered
     * options." runTacticalBotSearchJob() logs a 'tactical_bot_reasoning'
     * game_events row carrying exactly that, but only for a game that
     * opted into diagnostic mode.
     */
    public function testRunTacticalBotSearchJobLogsReasoningWhenDiagnosticModeIsOn(): void
    {
        ['bot' => $bot, 'gameId' => $gameId] = $this->createTacticalBotGame(diagnosticMode: true);
        $botPlayerId = $this->games->gamePlayerIdFor($gameId, $bot);

        $jobStmt = $this->pdo->prepare('SELECT id FROM bot_search_jobs WHERE game_player_id = :id ORDER BY id DESC LIMIT 1');
        $jobStmt->execute(['id' => $botPlayerId]);
        $jobId = (int) $jobStmt->fetchColumn();

        $this->games->runTacticalBotSearchJob($jobId);

        $eventStmt = $this->pdo->prepare(
            "SELECT details FROM game_events WHERE game_id = :game_id AND event_type = 'tactical_bot_reasoning' AND acting_game_player_id = :bot_id"
        );
        $eventStmt->execute(['game_id' => $gameId, 'bot_id' => $botPlayerId]);
        $details = $eventStmt->fetchColumn();
        self::assertNotFalse($details, 'diagnostic mode must log a reasoning event for the bot\'s own turn');

        $decoded = json_decode((string) $details, true);
        self::assertArrayHasKey('candidates', $decoded);
        self::assertArrayHasKey('excluded_by_heuristic', $decoded);
        self::assertNotEmpty($decoded['candidates'], 'at least one candidate (even just "pass") must always be recorded');
        foreach ($decoded['candidates'] as $candidate) {
            self::assertArrayHasKey('visits', $candidate);
            self::assertArrayHasKey('average_reward', $candidate);
        }
    }

    /**
     * Reported live, twice, as a real (obviously not "passed") play --
     * Melancholy, then Awe -- reading as "BotSageQuick passed" in the
     * dialog: every card_id an action carries throughout BotPlayerService/
     * SearchBotPlayerService is the per-game INSTANCE id
     * (game_cards.id, see gameCardInstanceId()'s own docblock), but the
     * frontend resolves a reasoning entry's own card_id against its
     * already-loaded CATALOG (deckBuilderCatalogById, keyed by the
     * catalog's own cards.id) -- the two only coincidentally match for a
     * low enough instance id, which is exactly why this went unnoticed
     * for as long as it did. tacticalBotReasoningSince() now translates
     * every card_id (the entry's own, every candidate's, every
     * heuristically-excluded one) via BoardState::catalogCardId() before
     * returning it.
     */
    public function testTacticalBotReasoningSinceReturnsCatalogIdsNotInstanceIds(): void
    {
        ['gameId' => $gameId, 'botPlayerId' => $botPlayerId] = $this->createRawTacticalBotGame($this->games, [55, 7], diagnosticMode: true); // Apathy, Courage
        $apathyInstanceId = $this->gameCardInstanceId($gameId, 55);
        self::assertNotSame(55, $apathyInstanceId, 'this test only proves anything if the instance id genuinely differs from the catalog id it stands for');

        $jobStmt = $this->pdo->prepare('SELECT id FROM bot_search_jobs WHERE game_player_id = :id ORDER BY id DESC LIMIT 1');
        $jobStmt->execute(['id' => $botPlayerId]);
        $jobId = (int) $jobStmt->fetchColumn();
        $this->games->runTacticalBotSearchJob($jobId);

        $humanUserId = (int) $this->pdo
            ->query("SELECT user_id FROM game_players WHERE game_id = {$gameId} AND id != {$botPlayerId}")
            ->fetchColumn();

        $reasoning = $this->games->tacticalBotReasoningSince($gameId, $humanUserId);

        self::assertCount(1, $reasoning);
        if ($reasoning[0]['card_id'] !== null) {
            self::assertContains($reasoning[0]['card_id'], [55, 7], 'must be the catalog id (what the frontend\'s catalog map is keyed by), not the per-game instance id');
        }
        foreach ($reasoning[0]['candidates'] as $candidate) {
            if ($candidate['card_id'] !== null) {
                self::assertContains($candidate['card_id'], [55, 7]);
            }
        }
    }

    /** The exact same turn, but without diagnostic mode -- no reasoning event should exist at all. */
    public function testRunTacticalBotSearchJobDoesNotLogReasoningWhenDiagnosticModeIsOff(): void
    {
        ['bot' => $bot, 'gameId' => $gameId] = $this->createTacticalBotGame(diagnosticMode: false);
        $botPlayerId = $this->games->gamePlayerIdFor($gameId, $bot);

        $jobStmt = $this->pdo->prepare('SELECT id FROM bot_search_jobs WHERE game_player_id = :id ORDER BY id DESC LIMIT 1');
        $jobStmt->execute(['id' => $botPlayerId]);
        $jobId = (int) $jobStmt->fetchColumn();

        $this->games->runTacticalBotSearchJob($jobId);

        $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM game_events WHERE game_id = :game_id AND event_type = 'tactical_bot_reasoning'");
        $countStmt->execute(['game_id' => $gameId]);
        self::assertSame(0, (int) $countStmt->fetchColumn());
    }

    /**
     * Reported live: "a button should be available to allow a human
     * player to view the bot(s) hand(s)." getState()'s own
     * diagnostic_bot_hands rides along live in the ordinary poll response
     * for a seated human, once the game has opted in.
     */
    public function testGetStateExposesTheBotsLiveHandWhenDiagnosticModeIsOn(): void
    {
        ['human' => $human, 'bot' => $bot, 'gameId' => $gameId] = $this->createTacticalBotGame(diagnosticMode: true);
        $botPlayerId = $this->games->gamePlayerIdFor($gameId, $bot);

        $state = $this->games->getState($gameId, $human);

        self::assertNotNull($state['diagnostic_bot_hands']);
        self::assertCount(1, $state['diagnostic_bot_hands']);
        self::assertSame($botPlayerId, $state['diagnostic_bot_hands'][0]['game_player_id']);
        self::assertSame(
            (int) $this->pdo->query("SELECT COUNT(*) FROM game_cards WHERE zone = 'hand' AND owner_game_player_id = {$botPlayerId}")->fetchColumn(),
            count($state['diagnostic_bot_hands'][0]['hand']),
        );
    }

    /** The exact same game, but never opted into diagnostic mode -- null, not an empty list. */
    public function testGetStateDoesNotExposeTheBotsHandWhenDiagnosticModeIsOff(): void
    {
        ['human' => $human, 'gameId' => $gameId] = $this->createTacticalBotGame(diagnosticMode: false);

        $state = $this->games->getState($gameId, $human);

        self::assertNull($state['diagnostic_bot_hands']);
    }

    /**
     * Reported live: "a button to show the 'reasoning' behind every play
     * the bot has made SINCE THE HUMAN PLAYER'S PREVIOUS PLAY." Scoped
     * per viewer: the human's own most recent game_events row marks the
     * boundary. Here the human passes once (their own "previous play"),
     * the bot then takes TWO turns (two reasoning events logged), and
     * tacticalBotReasoningSince() returns only those two, not anything
     * logged before the human's own pass.
     */
    /**
     * Exercises the boundary computation itself with directly-seeded
     * game_events rows rather than driving two full real turns through
     * createTacticalBotGame() -- a real turn can legitimately span
     * several plays (see testRunTacticalBotSearchJobAppliesItsActionAndMarksTheJobDone()'s
     * own docblock) or auto-pass with no job at all when the bot has no
     * legal play (see advanceAutomatedTurns()'s own tactical-bot branch),
     * neither of which this test cares about -- only that
     * tacticalBotReasoningSince() itself correctly excludes a
     * 'tactical_bot_reasoning' row at or before the viewer's own last
     * event and includes one after it.
     */
    public function testTacticalBotReasoningSinceOnlyReturnsEntriesAfterTheViewersOwnLastPlay(): void
    {
        ['human' => $human, 'bot' => $bot, 'gameId' => $gameId] = $this->createTacticalBotGame(diagnosticMode: true);
        $botPlayerId = $this->games->gamePlayerIdFor($gameId, $bot);
        $humanPlayerId = $this->games->gamePlayerIdFor($gameId, $human);

        $this->insertReasoningEvent($gameId, $botPlayerId, 'old reasoning, before the boundary');

        $stmt = $this->pdo->prepare(
            "INSERT INTO game_events (game_id, acting_game_player_id, event_type, details) VALUES (:game_id, :player_id, 'turn_passed', '{}')"
        );
        $stmt->execute(['game_id' => $gameId, 'player_id' => $humanPlayerId]);

        $this->insertReasoningEvent($gameId, $botPlayerId, 'new reasoning, after the boundary');

        $reasoning = $this->games->tacticalBotReasoningSince($gameId, $human);

        self::assertCount(1, $reasoning, 'only the reasoning logged AFTER the human\'s own last play should come back');
        self::assertSame($botPlayerId, $reasoning[0]['game_player_id']);
        self::assertSame('new reasoning, after the boundary', $reasoning[0]['candidates'][0]['note']);
    }

    /**
     * Reported live: "could we add some kind of reasoning text for the
     * default bots?" -- tacticalBotReasoningSince() now merges
     * 'heuristic_bot_reasoning' rows in alongside the Tactical Bot's own,
     * in the same chronological order, with `source` telling them apart.
     */
    public function testTacticalBotReasoningSinceMergesHeuristicAndTacticalEntriesChronologically(): void
    {
        ['human' => $human, 'bot' => $bot, 'gameId' => $gameId] = $this->createTacticalBotGame(diagnosticMode: true);
        $botPlayerId = $this->games->gamePlayerIdFor($gameId, $bot);

        $this->insertReasoningEvent($gameId, $botPlayerId, 'a tactical search result');
        $this->insertHeuristicReasoningEvent($gameId, $botPlayerId, 'bespoke_rule');

        $reasoning = $this->games->tacticalBotReasoningSince($gameId, $human);

        self::assertCount(2, $reasoning);
        self::assertSame('tactical', $reasoning[0]['source']);
        self::assertNull($reasoning[0]['choice_policy_path']);
        self::assertSame('heuristic', $reasoning[1]['source']);
        self::assertSame('bespoke_rule', $reasoning[1]['choice_policy_path']);
        self::assertSame([], $reasoning[1]['candidates'], 'a heuristic entry has no comparison of alternatives to report');
    }

    private function insertHeuristicReasoningEvent(int $gameId, int $botPlayerId, string $choicePolicyPath): void
    {
        $details = json_encode(['chosen_choices' => null, 'choice_policy_path' => $choicePolicyPath]);
        $stmt = $this->pdo->prepare(
            "INSERT INTO game_events (game_id, acting_game_player_id, event_type, details) VALUES (:game_id, :player_id, 'heuristic_bot_reasoning', :details)"
        );
        $stmt->execute(['game_id' => $gameId, 'player_id' => $botPlayerId, 'details' => $details]);
    }

    /**
     * Reported live: the "View bot reasoning" dialog showed empty even
     * right after a Tactical Bot's move was clearly visible in Recent
     * plays. Root cause: a scoring-time (Enthusiasm/Passion) or
     * after-scoring order decision logs its own 'pending_decision_created'
     * row with acting_game_player_id set to whoever now OWNS that
     * decision (see GameService::writeScoringDecisionBatch()/
     * writeAfterScoringOrderDecisionBatch()'s own call sites) -- which can
     * be the viewer purely because the round the bot's move was part of
     * happened to end in a decision now awaiting them, not because they
     * themselves did anything. tacticalBotReasoningSince() was treating
     * that row as "the viewer's own last play," pushing the boundary past
     * the very tactical_bot_reasoning row the decision resulted from.
     */
    public function testAPendingDecisionCreatedForTheViewerDoesNotCountAsTheirOwnPlay(): void
    {
        ['human' => $human, 'bot' => $bot, 'gameId' => $gameId] = $this->createTacticalBotGame(diagnosticMode: true);
        $botPlayerId = $this->games->gamePlayerIdFor($gameId, $bot);
        $humanPlayerId = $this->games->gamePlayerIdFor($gameId, $human);

        $this->insertReasoningEvent($gameId, $botPlayerId, 'reasoning behind the bot play that led to this decision');

        $stmt = $this->pdo->prepare(
            "INSERT INTO game_events (game_id, acting_game_player_id, event_type, details)
             VALUES (:game_id, :player_id, 'pending_decision_created', '{\"scoring_trigger\":true}')"
        );
        $stmt->execute(['game_id' => $gameId, 'player_id' => $humanPlayerId]);

        $reasoning = $this->games->tacticalBotReasoningSince($gameId, $human);

        self::assertCount(1, $reasoning, 'a decision merely becoming pending for the viewer is not their own play, and must not hide the bot reasoning that led to it');
    }

    /**
     * Reported live a second time: "it still seems to always show [empty]
     * when I click it at the beginning of my turn - can we change it to
     * show all reasoning since the end of my previous turn?" --
     * match_first_player_decided (a best-of-three match's loser choosing
     * who goes first in the NEXT game) logs acting_game_player_id as
     * whoever was CHOSEN to go first, an announcement about them, not a
     * decision they made -- if the viewer is that chosen player, this
     * used to count as "their own last play" the moment their new game's
     * very first turn began, exactly matching "at the beginning of my
     * turn." tacticalBotReasoningSince() was rewritten from a blocklist
     * (excluding known-bad event types one at a time as each was caught)
     * to an allowlist (only event types that genuinely represent the
     * viewer having just acted), so this -- and any other similar
     * bookkeeping type not yet caught live -- is never consulted here at
     * all.
     */
    public function testMatchFirstPlayerDecidedForTheViewerDoesNotCountAsTheirOwnPlay(): void
    {
        ['human' => $human, 'bot' => $bot, 'gameId' => $gameId] = $this->createTacticalBotGame(diagnosticMode: true);
        $botPlayerId = $this->games->gamePlayerIdFor($gameId, $bot);
        $humanPlayerId = $this->games->gamePlayerIdFor($gameId, $human);

        $this->insertReasoningEvent($gameId, $botPlayerId, 'reasoning behind the bot play from the previous game');

        $stmt = $this->pdo->prepare(
            "INSERT INTO game_events (game_id, acting_game_player_id, event_type, details)
             VALUES (:game_id, :player_id, 'match_first_player_decided', :details)"
        );
        $stmt->execute(['game_id' => $gameId, 'player_id' => $humanPlayerId, 'details' => json_encode(['game_player_id' => $humanPlayerId])]);

        $reasoning = $this->games->tacticalBotReasoningSince($gameId, $human);

        self::assertCount(1, $reasoning, 'being announced as the next game\'s first player is not the viewer\'s own play, and must not hide earlier bot reasoning');
    }

    /**
     * Reported live a third time: a full round's worth of Tactical Bot
     * plays (several extra-play chained moods, ending in an automatic
     * no-legal-play pass) went entirely missing from the dialog. Traced
     * to the round's own Enthusiasm/Passion "take the bonus?" decision --
     * resolved by the viewer immediately after those plays as part of
     * scoring, which logs its own 'pending_decision_resolved' row
     * (respondToDecision()'s own scoring-time branch, tagged
     * 'scoring_trigger') -- genuinely the viewer's own answer, but to a
     * prompt that happens automatically right after a round's plays with
     * no turn of the viewer's own in between, unlike an ordinary MID-TURN
     * decision resolution (no 'scoring_trigger' tag), which still counts.
     */
    public function testAScoringTimeDecisionResolvedByTheViewerDoesNotCountAsTheirOwnPlay(): void
    {
        ['human' => $human, 'bot' => $bot, 'gameId' => $gameId] = $this->createTacticalBotGame(diagnosticMode: true);
        $botPlayerId = $this->games->gamePlayerIdFor($gameId, $bot);
        $humanPlayerId = $this->games->gamePlayerIdFor($gameId, $human);

        $this->insertReasoningEvent($gameId, $botPlayerId, 'reasoning behind the round\'s own bot plays');

        $stmt = $this->pdo->prepare(
            "INSERT INTO game_events (game_id, acting_game_player_id, event_type, details)
             VALUES (:game_id, :player_id, 'pending_decision_resolved', '{\"take_bonus\":true,\"scoring_trigger\":true}')"
        );
        $stmt->execute(['game_id' => $gameId, 'player_id' => $humanPlayerId]);

        $reasoning = $this->games->tacticalBotReasoningSince($gameId, $human);

        self::assertCount(1, $reasoning, 'answering the round\'s own scoring-time bonus decision is not the viewer\'s own turn-ending play, and must not hide that round\'s own bot reasoning');
    }

    /** An ORDINARY mid-turn decision response (no scoring_trigger) is unaffected -- still counts as the viewer's own play, exactly as before. */
    public function testAnOrdinaryMidTurnDecisionResolvedByTheViewerStillCountsAsTheirOwnPlay(): void
    {
        ['human' => $human, 'bot' => $bot, 'gameId' => $gameId] = $this->createTacticalBotGame(diagnosticMode: true);
        $botPlayerId = $this->games->gamePlayerIdFor($gameId, $bot);
        $humanPlayerId = $this->games->gamePlayerIdFor($gameId, $human);

        $this->insertReasoningEvent($gameId, $botPlayerId, 'old reasoning, before the mid-turn decision');

        $stmt = $this->pdo->prepare(
            "INSERT INTO game_events (game_id, acting_game_player_id, event_type, details)
             VALUES (:game_id, :player_id, 'pending_decision_resolved', '{\"revealed_card_id\":1}')"
        );
        $stmt->execute(['game_id' => $gameId, 'player_id' => $humanPlayerId]);

        $this->insertReasoningEvent($gameId, $botPlayerId, 'new reasoning, after the mid-turn decision');

        $reasoning = $this->games->tacticalBotReasoningSince($gameId, $human);

        self::assertCount(1, $reasoning, 'a real mid-turn decision response still moves the boundary forward, same as before');
        self::assertSame('new reasoning, after the mid-turn decision', $reasoning[0]['candidates'][0]['note']);
    }

    /**
     * Reported live: a Tactical Bot's move was clearly visible in Recent
     * plays, yet "View bot reasoning" showed the same generic empty
     * message even though tacticalBotReasoningSince() was already scoped
     * correctly by this point (see the two tests above) -- suspected root
     * cause: a stale/crashed search job (or one whose own process threw)
     * falls back to the ordinary heuristic bot for that turn, which never
     * logs a tactical_bot_reasoning row at all. tacticalBotFallbackTurnsSince()
     * detects this: a mood_played row attributed to the Tactical Bot's
     * own seat with no matching reasoning row logged for it.
     */
    public function testTacticalBotFallbackTurnsSinceCountsAPlayWithNoReasoningLogged(): void
    {
        ['human' => $human, 'bot' => $bot, 'gameId' => $gameId] = $this->createTacticalBotGame(diagnosticMode: true);
        $botPlayerId = $this->games->gamePlayerIdFor($gameId, $bot);

        $stmt = $this->pdo->prepare(
            "INSERT INTO game_events (game_id, acting_game_player_id, event_type, details) VALUES (:game_id, :player_id, 'mood_played', '{}')"
        );
        $stmt->execute(['game_id' => $gameId, 'player_id' => $botPlayerId]);

        self::assertSame(1, $this->games->tacticalBotFallbackTurnsSince($gameId, $human));
    }

    /** A real search-backed play logs its own reasoning immediately before the resulting mood_played row -- nothing unexplained here. */
    public function testTacticalBotFallbackTurnsSinceIsZeroWhenReasoningWasLogged(): void
    {
        ['human' => $human, 'bot' => $bot, 'gameId' => $gameId] = $this->createTacticalBotGame(diagnosticMode: true);
        $botPlayerId = $this->games->gamePlayerIdFor($gameId, $bot);

        $this->insertReasoningEvent($gameId, $botPlayerId, 'reasoning behind this play');
        $stmt = $this->pdo->prepare(
            "INSERT INTO game_events (game_id, acting_game_player_id, event_type, details) VALUES (:game_id, :player_id, 'mood_played', '{}')"
        );
        $stmt->execute(['game_id' => $gameId, 'player_id' => $botPlayerId]);

        self::assertSame(0, $this->games->tacticalBotFallbackTurnsSince($gameId, $human));
    }

    public function testTacticalBotFallbackTurnsSinceIsZeroWithNothingSinceTheBoundary(): void
    {
        ['human' => $human, 'gameId' => $gameId] = $this->createTacticalBotGame(diagnosticMode: true);

        self::assertSame(0, $this->games->tacticalBotFallbackTurnsSince($gameId, $human));
    }

    private function insertReasoningEvent(int $gameId, int $botPlayerId, string $note): void
    {
        $details = json_encode([
            'chosen_choices' => null,
            'excluded_by_heuristic' => [],
            'candidates' => [['note' => $note]],
        ]);
        $stmt = $this->pdo->prepare(
            "INSERT INTO game_events (game_id, acting_game_player_id, event_type, details) VALUES (:game_id, :player_id, 'tactical_bot_reasoning', :details)"
        );
        $stmt->execute(['game_id' => $gameId, 'player_id' => $botPlayerId, 'details' => $details]);
    }

    public function testTacticalBotReasoningSinceThrowsForANonDiagnosticGame(): void
    {
        ['human' => $human, 'gameId' => $gameId] = $this->createTacticalBotGame(diagnosticMode: false);

        $this->expectException(\MoodSwings\Game\Exceptions\GameStateException::class);
        $this->games->tacticalBotReasoningSince($gameId, $human);
    }

    public function testTacticalBotReasoningSinceThrowsForAnUnseatedUser(): void
    {
        ['gameId' => $gameId] = $this->createTacticalBotGame(diagnosticMode: true);
        $outsider = $this->insertUser('diag-outsider');

        $this->expectException(\MoodSwings\Game\Exceptions\GameStateException::class);
        $this->games->tacticalBotReasoningSince($gameId, $outsider);
    }
}
