<?php

declare(strict_types=1);

namespace MoodSwings\Tests\Matchmaking;

use MoodSwings\Deck\UserDecklistService;
use MoodSwings\Friends\FriendshipService;
use MoodSwings\Game\BoardStateRepository;
use MoodSwings\Game\Exceptions\GameStateException;
use MoodSwings\Game\GameService;
use MoodSwings\Game\ReplayStateBuilder;
use MoodSwings\Matchmaking\AlreadyJoinedListingException;
use MoodSwings\Matchmaking\MatchmakingService;
use MoodSwings\Matchmaking\NotAuthorizedToCancelListingException;
use MoodSwings\Matchmaking\NotDiscoverableException;
use MoodSwings\Matchmaking\OpenGameListingNotFoundException;
use MoodSwings\Repository\FriendshipRepository;
use MoodSwings\Repository\OpenGameListingRepository;
use MoodSwings\Repository\UserDecklistRepository;
use MoodSwings\Repository\UserRepository;
use MoodSwings\Rules\DefaultEffectRegistry;
use MoodSwings\Rules\MoodPlayService;
use MoodSwings\Rules\RoundScorer;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

final class MatchmakingIntegrationTest extends TestCase
{
    private PDO $pdo;
    private MatchmakingService $matchmaking;
    private UserRepository $users;
    private FriendshipRepository $friendships;

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
        $pdo->exec('TRUNCATE TABLE open_game_listing_joins');
        $pdo->exec('TRUNCATE TABLE open_game_listings');
        $pdo->exec('TRUNCATE TABLE game_events');
        $pdo->exec('TRUNCATE TABLE game_notes');
        $pdo->exec('TRUNCATE TABLE game_chat_messages');
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
        $games = new GameService(
            new BoardStateRepository($registry),
            new MoodPlayService($registry),
            new RoundScorer(),
            $userDecklists,
            new ReplayStateBuilder($registry),
        );

        $this->users = new UserRepository();
        $this->friendships = new FriendshipRepository();
        $this->matchmaking = new MatchmakingService(
            new OpenGameListingRepository(),
            $this->users,
            $this->friendships,
            $games,
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

    private function makeDiscoverable(int $userId): void
    {
        $this->users->setMatchmakingDiscoverable($userId, true);
    }

    private function block(int $userAId, int $userBId, int $actorUserId): void
    {
        [$low, $high] = $userAId < $userBId ? [$userAId, $userBId] : [$userBId, $userAId];
        $this->friendships->create($low, $high, 'blocked', $actorUserId);
    }

    /** @param array{format?: string, deck_type?: string} $params */
    private function postDuel(int $userId, array $params = []): int
    {
        return $this->matchmaking->postOpenGame($userId, ['format' => 'duel', 'deck_type' => 'structure', ...$params], 2);
    }

    public function testPostingWithoutOptingInFails(): void
    {
        $aliceId = $this->insertUser('alice');

        $this->expectException(NotDiscoverableException::class);
        $this->postDuel($aliceId);
    }

    public function testUnsupportedFormatIsRejected(): void
    {
        $aliceId = $this->insertUser('alice');
        $this->makeDiscoverable($aliceId);

        $this->expectException(GameStateException::class);
        $this->matchmaking->postOpenGame($aliceId, ['format' => 'not_a_real_format'], 2);
    }

    public function testTargetPlayerCountIsForcedForDuelRegardlessOfWhatsPassed(): void
    {
        $aliceId = $this->insertUser('alice');
        $this->makeDiscoverable($aliceId);

        $this->matchmaking->postOpenGame($aliceId, ['format' => 'duel', 'deck_type' => 'structure'], 4);

        self::assertSame(2, $this->matchmaking->listMyOpenGames($aliceId)[0]['target_player_count']);
    }

    public function testTargetPlayerCountIsForcedToFourForTeamFormats(): void
    {
        $aliceId = $this->insertUser('alice');
        $this->makeDiscoverable($aliceId);

        $this->matchmaking->postOpenGame($aliceId, ['format' => 'team', 'deck_type' => 'structure'], 2);

        self::assertSame(4, $this->matchmaking->listMyOpenGames($aliceId)[0]['target_player_count']);
    }

    public function testTargetPlayerCountOutOfRangeIsRejectedForStandard(): void
    {
        $aliceId = $this->insertUser('alice');
        $this->makeDiscoverable($aliceId);

        $this->expectException(GameStateException::class);
        $this->matchmaking->postOpenGame($aliceId, ['format' => 'standard', 'deck_type' => 'structure'], 5);
    }

    public function testListOpenGamesExcludesOwnListing(): void
    {
        $aliceId = $this->insertUser('alice');
        $this->makeDiscoverable($aliceId);

        $this->postDuel($aliceId);

        self::assertCount(0, $this->matchmaking->listOpenGames($aliceId));
        self::assertCount(1, $this->matchmaking->listMyOpenGames($aliceId));
    }

    public function testListOpenGamesExcludesNonDiscoverableCreator(): void
    {
        $aliceId = $this->insertUser('alice');
        $bobId = $this->insertUser('bob');
        // Deliberately NOT opted in -- postOpenGame() itself would refuse
        // this, so insert the listing directly to exercise listOpenFor()'s
        // own filter in isolation (covers a listing posted before the
        // creator later opted back out, not just "never opted in").
        $this->makeDiscoverable($aliceId);
        $listingId = $this->postDuel($aliceId);
        $this->users->setMatchmakingDiscoverable($aliceId, false);

        self::assertCount(0, $this->matchmaking->listOpenGames($bobId));

        // Confirm it reappears once the creator opts back in, so the
        // "excluded" assertion above is actually exercising the filter
        // and not some other reason (e.g. a typo'd listing id).
        $this->users->setMatchmakingDiscoverable($aliceId, true);
        self::assertCount(1, $this->matchmaking->listOpenGames($bobId));
        self::assertSame($listingId, $this->matchmaking->listOpenGames($bobId)[0]['id']);
    }

    public function testListOpenGamesExcludesBlockedPairEitherDirection(): void
    {
        $aliceId = $this->insertUser('alice');
        $bobId = $this->insertUser('bob');
        $this->makeDiscoverable($aliceId);
        $this->postDuel($aliceId);

        $this->block($aliceId, $bobId, $bobId);

        self::assertCount(0, $this->matchmaking->listOpenGames($bobId));
    }

    public function testJoinCreatesADuelGameAndClaimsTheListing(): void
    {
        $aliceId = $this->insertUser('alice');
        $bobId = $this->insertUser('bob');
        $this->makeDiscoverable($aliceId);

        $listingId = $this->postDuel($aliceId);

        $result = $this->matchmaking->joinOpenGame($bobId, $listingId);
        self::assertSame('started', $result['status']);
        $gameId = $result['game_id'];

        $game = $this->pdo->query("SELECT format, deck_type FROM games WHERE id = {$gameId}")->fetch();
        self::assertSame('duel', $game['format']);
        self::assertSame('structure', $game['deck_type']);

        $seatedUserIds = $this->pdo->query("SELECT user_id FROM game_players WHERE game_id = {$gameId} ORDER BY seat_order")
            ->fetchAll(PDO::FETCH_COLUMN);
        self::assertSame([$aliceId, $bobId], array_map(intval(...), $seatedUserIds));

        self::assertCount(0, $this->matchmaking->listOpenGames($bobId));
        self::assertCount(0, $this->matchmaking->listMyOpenGames($aliceId));
    }

    public function testCannotJoinOwnListing(): void
    {
        $aliceId = $this->insertUser('alice');
        $this->makeDiscoverable($aliceId);
        $listingId = $this->postDuel($aliceId);

        $this->expectException(OpenGameListingNotFoundException::class);
        $this->matchmaking->joinOpenGame($aliceId, $listingId);
    }

    public function testCannotJoinAlreadyClaimedListing(): void
    {
        $aliceId = $this->insertUser('alice');
        $bobId = $this->insertUser('bob');
        $carolId = $this->insertUser('carol');
        $this->makeDiscoverable($aliceId);
        $listingId = $this->postDuel($aliceId);

        $this->matchmaking->joinOpenGame($bobId, $listingId);

        $this->expectException(OpenGameListingNotFoundException::class);
        $this->matchmaking->joinOpenGame($carolId, $listingId);
    }

    public function testCannotJoinTheSameListingTwice(): void
    {
        $aliceId = $this->insertUser('alice');
        $bobId = $this->insertUser('bob');
        $this->makeDiscoverable($aliceId);
        $listingId = $this->matchmaking->postOpenGame($aliceId, ['format' => 'standard', 'deck_type' => 'structure'], 3);

        $this->matchmaking->joinOpenGame($bobId, $listingId);

        $this->expectException(AlreadyJoinedListingException::class);
        $this->matchmaking->joinOpenGame($bobId, $listingId);
    }

    public function testCannotJoinWhenBlocked(): void
    {
        $aliceId = $this->insertUser('alice');
        $bobId = $this->insertUser('bob');
        $this->makeDiscoverable($aliceId);
        $listingId = $this->postDuel($aliceId);

        $this->block($aliceId, $bobId, $aliceId);

        $this->expectException(OpenGameListingNotFoundException::class);
        $this->matchmaking->joinOpenGame($bobId, $listingId);
    }

    public function testCreatorCanCancelTheirOwnOpenListing(): void
    {
        $aliceId = $this->insertUser('alice');
        $bobId = $this->insertUser('bob');
        $this->makeDiscoverable($aliceId);
        $listingId = $this->postDuel($aliceId);

        $this->matchmaking->cancelOpenGame($aliceId, $listingId);

        self::assertCount(0, $this->matchmaking->listMyOpenGames($aliceId));

        $this->expectException(OpenGameListingNotFoundException::class);
        $this->matchmaking->joinOpenGame($bobId, $listingId);
    }

    public function testNonCreatorCannotCancelListing(): void
    {
        $aliceId = $this->insertUser('alice');
        $bobId = $this->insertUser('bob');
        $this->makeDiscoverable($aliceId);
        $listingId = $this->postDuel($aliceId);

        $this->expectException(NotAuthorizedToCancelListingException::class);
        $this->matchmaking->cancelOpenGame($bobId, $listingId);
    }

    public function testJoinASecondPlayerCanBuildADraftGame(): void
    {
        $aliceId = $this->insertUser('alice');
        $bobId = $this->insertUser('bob');
        $this->makeDiscoverable($aliceId);

        $listingId = $this->matchmaking->postOpenGame($aliceId, [
            'format' => 'draft',
            'deck_type' => 'quick_draft',
            'quick_draft_pool_source' => 'random_48',
        ], 2);

        $result = $this->matchmaking->joinOpenGame($bobId, $listingId);
        self::assertSame('started', $result['status']);

        $game = $this->pdo->query("SELECT format, deck_type, status FROM games WHERE id = {$result['game_id']}")->fetch();
        self::assertSame('draft', $game['format']);
        self::assertSame('quick_draft', $game['deck_type']);
        self::assertSame('waiting', $game['status']);
    }

    /**
     * A 'standard' listing targeting 3 players stays 'open' -- with a
     * partial roster -- until all 3 have joined, rather than starting
     * (or claiming) after just the first joiner the way a 2-player
     * listing always has.
     */
    public function testStandardListingWaitsForEveryJoinerBeforeStarting(): void
    {
        $aliceId = $this->insertUser('alice');
        $bobId = $this->insertUser('bob');
        $carolId = $this->insertUser('carol');
        $this->makeDiscoverable($aliceId);

        $listingId = $this->matchmaking->postOpenGame($aliceId, ['format' => 'standard', 'deck_type' => 'structure'], 3);

        $afterBob = $this->matchmaking->joinOpenGame($bobId, $listingId);
        self::assertSame('waiting', $afterBob['status']);
        self::assertSame(1, $afterBob['joined_count']);
        self::assertSame(3, $afterBob['target_player_count']);

        // Still open and still listed, now showing 1 joined -- not
        // claimed, no game created yet.
        $myListings = $this->matchmaking->listMyOpenGames($aliceId);
        self::assertCount(1, $myListings);
        self::assertSame(1, $myListings[0]['joined_count']);
        self::assertSame(0, $this->pdo->query('SELECT COUNT(*) FROM games')->fetchColumn());

        $afterCarol = $this->matchmaking->joinOpenGame($carolId, $listingId);
        self::assertSame('started', $afterCarol['status']);

        $gameId = $afterCarol['game_id'];
        $game = $this->pdo->query("SELECT format FROM games WHERE id = {$gameId}")->fetch();
        self::assertSame('standard', $game['format']);

        $seatedUserIds = $this->pdo->query("SELECT user_id FROM game_players WHERE game_id = {$gameId} ORDER BY seat_order")
            ->fetchAll(PDO::FETCH_COLUMN);
        self::assertSame([$aliceId, $bobId, $carolId], array_map(intval(...), $seatedUserIds));

        self::assertCount(0, $this->matchmaking->listMyOpenGames($aliceId));
    }

    /**
     * A team-format listing always needs exactly 4 (forced regardless of
     * what's posted, see testTargetPlayerCountIsForcedToFourForTeamFormats()),
     * and teams are assigned randomly once full -- there's no way to know
     * a partner ahead of time when every joiner is a stranger, so this
     * never reads a partner_user_id from create_game_params (there never
     * is one). Confirms the resulting game actually has two 2-player
     * teams, without asserting which specific pairing (that's random).
     */
    public function testTeamListingAssignsRandomTeamsOnceAllFourHaveJoined(): void
    {
        $aliceId = $this->insertUser('alice');
        $bobId = $this->insertUser('bob');
        $carolId = $this->insertUser('carol');
        $daveId = $this->insertUser('dave');
        $this->makeDiscoverable($aliceId);

        $listingId = $this->matchmaking->postOpenGame($aliceId, ['format' => 'team', 'deck_type' => 'structure'], 4);

        self::assertSame('waiting', $this->matchmaking->joinOpenGame($bobId, $listingId)['status']);
        self::assertSame('waiting', $this->matchmaking->joinOpenGame($carolId, $listingId)['status']);
        $result = $this->matchmaking->joinOpenGame($daveId, $listingId);
        self::assertSame('started', $result['status']);

        $gameId = $result['game_id'];
        $game = $this->pdo->query("SELECT format FROM games WHERE id = {$gameId}")->fetch();
        self::assertSame('team', $game['format']);

        $teamIds = $this->pdo->query("SELECT DISTINCT team_id FROM game_players WHERE game_id = {$gameId}")
            ->fetchAll(PDO::FETCH_COLUMN);
        self::assertCount(2, $teamIds, 'exactly two teams');

        $teamCounts = $this->pdo->query("SELECT team_id, COUNT(*) AS c FROM game_players WHERE game_id = {$gameId} GROUP BY team_id")
            ->fetchAll();
        foreach ($teamCounts as $row) {
            self::assertSame(2, (int) $row['c'], 'each team has exactly 2 members');
        }
    }

    public function testUserCanLeaveAListingTheyJoinedBeforeItFills(): void
    {
        $aliceId = $this->insertUser('alice');
        $bobId = $this->insertUser('bob');
        $carolId = $this->insertUser('carol');
        $this->makeDiscoverable($aliceId);

        $listingId = $this->matchmaking->postOpenGame($aliceId, ['format' => 'standard', 'deck_type' => 'structure'], 3);

        $this->matchmaking->joinOpenGame($bobId, $listingId);
        self::assertCount(1, $this->matchmaking->listJoinedOpenGames($bobId));

        $this->matchmaking->leaveOpenGame($bobId, $listingId);
        self::assertCount(0, $this->matchmaking->listJoinedOpenGames($bobId));

        // Bob backed out, so the listing should show 0 joined again and
        // still needs 2 more (carol alone isn't enough to start it).
        self::assertSame(0, $this->matchmaking->listMyOpenGames($aliceId)[0]['joined_count']);

        $result = $this->matchmaking->joinOpenGame($bobId, $listingId);
        self::assertSame('waiting', $result['status'], 'bob can rejoin after leaving');
    }

    public function testCannotLeaveAListingNeverJoined(): void
    {
        $aliceId = $this->insertUser('alice');
        $bobId = $this->insertUser('bob');
        $this->makeDiscoverable($aliceId);
        $listingId = $this->matchmaking->postOpenGame($aliceId, ['format' => 'standard', 'deck_type' => 'structure'], 3);

        $this->expectException(OpenGameListingNotFoundException::class);
        $this->matchmaking->leaveOpenGame($bobId, $listingId);
    }

    /**
     * A listing the viewer has already joined shouldn't also show up in
     * their own "available to join" browse list (a "Join" button makes
     * no sense there) -- it belongs in listJoinedOpenGames() instead.
     */
    public function testListOpenGamesExcludesAListingTheViewerHasAlreadyJoined(): void
    {
        $aliceId = $this->insertUser('alice');
        $bobId = $this->insertUser('bob');
        $this->makeDiscoverable($aliceId);
        $listingId = $this->matchmaking->postOpenGame($aliceId, ['format' => 'standard', 'deck_type' => 'structure'], 3);

        self::assertCount(1, $this->matchmaking->listOpenGames($bobId));

        $this->matchmaking->joinOpenGame($bobId, $listingId);

        self::assertCount(0, $this->matchmaking->listOpenGames($bobId));
        self::assertCount(1, $this->matchmaking->listJoinedOpenGames($bobId));
    }
}
