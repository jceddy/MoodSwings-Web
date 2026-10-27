<?php

declare(strict_types=1);

namespace MoodSwings\Tests\League;

use MoodSwings\Deck\UserDecklistService;
use MoodSwings\Friends\FriendshipService;
use MoodSwings\Game\BoardStateRepository;
use MoodSwings\Game\Exceptions\GameStateException;
use MoodSwings\Game\GameService;
use MoodSwings\Game\ReplayStateBuilder;
use MoodSwings\League\LeagueService;
use MoodSwings\Repository\FriendshipRepository;
use MoodSwings\Repository\LeagueEventRepository;
use MoodSwings\Repository\UserDecklistRepository;
use MoodSwings\Repository\UserRepository;
use MoodSwings\Rules\DefaultEffectRegistry;
use MoodSwings\Rules\MoodPlayService;
use MoodSwings\Rules\RoundScorer;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class LeagueServiceIntegrationTest extends TestCase
{
    private PDO $pdo;
    private LeagueService $league;
    private LeagueEventRepository $events;
    private UserDecklistRepository $decklists;

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
        foreach ([
            'league_matches', 'league_queue', 'league_participants', 'league_events', 'user_collection_cards',
            'game_matches', 'game_events', 'game_notes', 'game_chat_messages', 'game_pending_decisions',
            'game_pending_decision_batches', 'game_round_scores', 'game_cards', 'game_rounds', 'game_players',
            'games', 'user_decklists', 'user_lifetime_stats', 'friendships', 'users',
        ] as $table) {
            $pdo->exec("TRUNCATE TABLE {$table}");
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

        putenv("DB_HOST={$host}");
        putenv("DB_PORT={$port}");
        putenv("DB_NAME={$name}");
        putenv("DB_USER={$user}");
        putenv("DB_PASSWORD={$password}");

        $this->pdo = $pdo;

        $registry = DefaultEffectRegistry::build();
        $games = new GameService(
            new BoardStateRepository($registry),
            new MoodPlayService($registry),
            new RoundScorer(),
            new UserDecklistService(new UserDecklistRepository(), new FriendshipService(new UserRepository(), new FriendshipRepository())),
            new ReplayStateBuilder($registry),
            spawnAutomatedTurnRecheckProcesses: false,
        );

        $this->events = new LeagueEventRepository();
        $this->decklists = new UserDecklistRepository();
        $this->league = new LeagueService($games, $this->events, new UserRepository(), new FriendshipRepository(), $this->decklists);
    }

    private function insertUser(string $username, bool $discoverable = true, bool $bot = false): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO users (username, email, password_hash, email_verified_at, matchmaking_discoverable, is_bot)
             VALUES (:username, :email, 'hash', NOW(), :discoverable, :bot)"
        );
        $stmt->execute(['username' => $username, 'email' => "{$username}@example.com", 'discoverable' => $discoverable ? 1 : 0, 'bot' => $bot ? 1 : 0]);

        return (int) $this->pdo->lastInsertId();
    }

    /** The granted structure deck is saved automatically on a player's first join -- but joining needs a deck, so this makes the grant happen via a throwaway first attempt. */
    private function grantedDeckId(int $userId): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM user_decklists WHERE user_id = :user_id ORDER BY id LIMIT 1');
        $stmt->execute(['user_id' => $userId]);
        $id = $stmt->fetchColumn();
        self::assertNotFalse($id, 'the first join should have saved the granted deck');

        return (int) $id;
    }

    /** A brand-new player's first join names no deck: they play the one they were just granted. */
    private function firstJoin(int $userId): array
    {
        return $this->league->joinAndQueue($userId, null);
    }

    private function startEvent(): int
    {
        return $this->events->start('Test League', 'TST');
    }

    public function testJoiningWithNoActiveEventIsRejected(): void
    {
        $userId = $this->insertUser('alice');

        $this->expectException(GameStateException::class);
        $this->league->joinAndQueue($userId, 1);
    }

    public function testOnlyOneEventCanBeActiveAndEndingEmptiesTheQueue(): void
    {
        $this->startEvent();
        try {
            $this->events->start('Second', null);
            self::fail('a second active event should be refused');
        } catch (RuntimeException) {
            self::addToAssertionCount(1);
        }

        $alice = $this->insertUser('alice');
        $this->firstJoin($alice);
        self::assertTrue($this->league->statusFor($alice)['queued']);

        $this->events->endActive();

        self::assertNull($this->league->statusFor($alice)['event']);
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM league_queue')->fetchColumn());
    }

    public function testPracticeBotsAndNonDiscoverablePlayersCannotJoin(): void
    {
        $this->startEvent();
        $bot = $this->insertUser('bot', true, true);
        $private = $this->insertUser('private', false);

        foreach ([$bot, $private] as $userId) {
            try {
                $this->league->joinAndQueue($userId, 1);
                self::fail('should have been refused');
            } catch (GameStateException) {
                self::addToAssertionCount(1);
            }
        }
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM league_participants')->fetchColumn());
    }

    public function testFirstJoinGrantsAStructureDeckOnceAndQueues(): void
    {
        $this->startEvent();
        $alice = $this->insertUser('alice');

        $result = $this->firstJoin($alice);

        self::assertSame('waiting', $result['status']);
        $status = $this->league->statusFor($alice);
        self::assertTrue($status['joined']);
        self::assertTrue($status['queued']);
        self::assertSame(45, $status['collection']['total_cards']);

        $rarities = $this->pdo->query(
            "SELECT k.rarity, SUM(c.copies) AS n FROM user_collection_cards c JOIN cards k ON k.id = c.card_id WHERE c.user_id = {$alice} GROUP BY k.rarity"
        )->fetchAll(PDO::FETCH_KEY_PAIR);
        self::assertSame(['common' => 23, 'uncommon' => 14, 'rare' => 6, 'mythic' => 2], array_map('intval', $rarities));
        self::assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM user_collection_cards c JOIN cards k ON k.id = c.card_id WHERE k.is_token = 1")->fetchColumn());

        // Leaving and rejoining the same event does not grant a second deck.
        $this->league->leaveQueue($alice);
        $this->league->joinAndQueue($alice, $this->grantedDeckId($alice));
        self::assertSame(45, $this->league->statusFor($alice)['collection']['total_cards']);
    }

    public function testASecondPlayerIsPairedIntoABestOfThreeDuelWithSeededDecks(): void
    {
        $this->startEvent();
        $alice = $this->insertUser('alice');
        $bob = $this->insertUser('bob');

        $this->firstJoin($alice);
        $paired = $this->firstJoin($bob);

        self::assertSame('paired', $paired['status']);
        self::assertSame('alice', $paired['opponent_username']);

        $game = $this->pdo->query('SELECT * FROM games WHERE id = ' . (int) $paired['game_id'])->fetch();
        self::assertSame('custom_duel', $game['deck_type']);
        self::assertSame('duel', $game['format']);
        self::assertSame('in_progress', $game['status'], 'both decks were seeded, so the match starts straight away');
        self::assertNotNull($game['game_match_id']);
        self::assertSame(12, (int) $game['custom_duel_min_cards']);
        self::assertEquals(['mythic' => 2, 'rare' => 6], json_decode((string) $game['custom_duel_rarity_limits'], true));

        $decks = $this->pdo->query('SELECT custom_deck_card_ids FROM game_players WHERE game_id = ' . (int) $paired['game_id'])->fetchAll(PDO::FETCH_COLUMN);
        self::assertCount(2, $decks);
        foreach ($decks as $deck) {
            self::assertCount(45, json_decode((string) $deck, true));
        }

        self::assertSame(1, $this->league->statusFor($alice)['in_progress_count']);
        self::assertFalse($this->league->statusFor($alice)['queued']);
        self::assertSame('in_progress', $this->league->statusFor($bob)['matches'][0]['status']);
    }

    public function testPlayersNeverFaceTheSameOpponentTwiceInOneEvent(): void
    {
        $this->startEvent();
        $alice = $this->insertUser('alice');
        $bob = $this->insertUser('bob');

        $this->firstJoin($alice);
        $this->firstJoin($bob);

        // Both queue again; they've already played each other, so neither is paired.
        self::assertSame('waiting', $this->league->joinAndQueue($alice, $this->grantedDeckId($alice))['status']);
        self::assertSame('waiting', $this->league->joinAndQueue($bob, $this->grantedDeckId($bob))['status']);

        // A newcomer is paired with whoever has waited longest -- alice.
        $carol = $this->insertUser('carol');
        $result = $this->firstJoin($carol);
        self::assertSame('paired', $result['status']);
        self::assertSame('alice', $result['opponent_username']);
    }

    public function testBlockedPairsAreNotMatched(): void
    {
        $this->startEvent();
        $alice = $this->insertUser('alice');
        $bob = $this->insertUser('bob');
        $this->pdo->prepare("INSERT INTO friendships (user_low_id, user_high_id, status, action_user_id) VALUES (:low, :high, 'blocked', :actor)")
            ->execute(['low' => min($alice, $bob), 'high' => max($alice, $bob), 'actor' => $alice]);

        $this->firstJoin($alice);

        self::assertSame('waiting', $this->firstJoin($bob)['status']);
    }

    public function testConcurrentMatchCapStopsAFurtherQueueEntry(): void
    {
        $this->startEvent();
        $alice = $this->insertUser('alice');
        $bob = $this->insertUser('bob');
        $carol = $this->insertUser('carol');

        $this->firstJoin($alice);
        $this->firstJoin($bob);
        $this->league->joinAndQueue($alice, $this->grantedDeckId($alice));
        $this->firstJoin($carol);
        self::assertSame(LeagueService::CONCURRENT_MATCH_CAP, $this->league->statusFor($alice)['in_progress_count']);

        $this->expectException(GameStateException::class);
        $this->expectExceptionMessage('in progress');
        $this->league->joinAndQueue($alice, $this->grantedDeckId($alice));
    }

    public function testDecklistsMustFitTheCollectionAndTheLeagueRules(): void
    {
        $this->startEvent();
        $alice = $this->insertUser('alice');
        $this->firstJoin($alice);
        $this->league->leaveQueue($alice);

        $granted = (array) json_decode((string) $this->pdo->query("SELECT granted_card_ids FROM league_participants WHERE user_id = {$alice}")->fetchColumn(), true);

        // A card that was never granted.
        $unowned = (int) $this->pdo->query(
            'SELECT id FROM cards WHERE is_token = 0 AND id NOT IN (' . implode(',', $granted) . ") AND rarity = 'common' LIMIT 1"
        )->fetchColumn();
        $deckWithUnowned = $this->decklists->create($alice, 'Unowned', array_merge(array_slice($granted, 0, 11), [$unowned]), null, 'private');
        try {
            $this->league->joinAndQueue($alice, $deckWithUnowned);
            self::fail('a card outside the collection should be refused');
        } catch (GameStateException $e) {
            self::assertStringContainsString("collection doesn't have it", $e->getMessage());
        }

        // Too many copies of a card that was granted once.
        $twice = $this->decklists->create($alice, 'Twice', array_merge(array_slice($granted, 0, 11), [$granted[0]]), null, 'private');
        try {
            $this->league->joinAndQueue($alice, $twice);
            self::fail('more copies than owned should be refused');
        } catch (GameStateException $e) {
            self::assertStringContainsString('only has 1', $e->getMessage());
        }

        // Fewer than 12 cards.
        $small = $this->decklists->create($alice, 'Small', array_slice($granted, 0, 11), null, 'private');
        try {
            $this->league->joinAndQueue($alice, $small);
            self::fail('an undersized deck should be refused');
        } catch (GameStateException $e) {
            self::assertStringContainsString('at least 12', $e->getMessage());
        }

        // Exactly 12 owned cards is legal.
        $legal = $this->decklists->create($alice, 'Legal', array_slice($granted, 0, 12), null, 'private');
        self::assertSame('waiting', $this->league->joinAndQueue($alice, $legal)['status']);
    }

    public function testMythicAndRareCapsApplyEvenWhenOwned(): void
    {
        $this->startEvent();
        $alice = $this->insertUser('alice');
        $this->firstJoin($alice);
        $this->league->leaveQueue($alice);

        // Give alice plenty of mythics so only the cap can stop a deck with three.
        $mythics = array_map('intval', $this->pdo->query("SELECT id FROM cards WHERE rarity = 'mythic' AND is_token = 0 LIMIT 3")->fetchAll(PDO::FETCH_COLUMN));
        foreach ($mythics as $cardId) {
            $this->pdo->exec("INSERT INTO user_collection_cards (user_id, card_id, copies) VALUES ({$alice}, {$cardId}, 1) ON DUPLICATE KEY UPDATE copies = GREATEST(copies, 1)");
        }
        $commons = array_map('intval', $this->pdo->query("SELECT card_id FROM user_collection_cards c JOIN cards k ON k.id = c.card_id WHERE c.user_id = {$alice} AND k.rarity = 'common' LIMIT 9")->fetchAll(PDO::FETCH_COLUMN));

        $threeMythics = $this->decklists->create($alice, 'Three mythics', array_merge($mythics, $commons), null, 'private');
        $this->expectException(GameStateException::class);
        $this->expectExceptionMessage('at most 2 mythic');
        $this->league->joinAndQueue($alice, $threeMythics);
    }

    public function testCompletedMatchesAreCountedInTheRecordAndFreeAConcurrentSlot(): void
    {
        $this->startEvent();
        $alice = $this->insertUser('alice');
        $bob = $this->insertUser('bob');

        $this->firstJoin($alice);
        $paired = $this->firstJoin($bob);

        $gameMatchId = (int) $this->pdo->query('SELECT game_match_id FROM games WHERE id = ' . (int) $paired['game_id'])->fetchColumn();
        $this->pdo->exec("UPDATE game_matches SET status = 'completed', winner_user_id = {$bob}, completed_at = NOW() WHERE id = {$gameMatchId}");

        $aliceStatus = $this->league->statusFor($alice);
        self::assertSame(0, $aliceStatus['in_progress_count']);
        self::assertSame(0, $aliceStatus['wins']);
        self::assertSame(1, $aliceStatus['losses']);
        self::assertFalse($aliceStatus['matches'][0]['won']);

        $bobStatus = $this->league->statusFor($bob);
        self::assertSame(1, $bobStatus['wins']);
        self::assertTrue($bobStatus['matches'][0]['won']);

        // A match whose wrapper was cleaned up is abandoned, not counted, and no longer blocks a rematch.
        $this->league->joinAndQueue($alice, $this->grantedDeckId($alice));
        $this->league->leaveQueue($alice);
        $this->pdo->exec("UPDATE league_matches SET status = 'abandoned'");
        self::assertSame('waiting', $this->league->joinAndQueue($alice, $this->grantedDeckId($alice))['status']);
        self::assertSame('paired', $this->league->joinAndQueue($bob, $this->grantedDeckId($bob))['status']);
    }

    public function testCollectionListsOwnedCardsWithCopies(): void
    {
        $this->startEvent();
        $alice = $this->insertUser('alice');
        $this->firstJoin($alice);

        $collection = $this->league->collectionFor($alice);

        self::assertCount(45, $collection);
        self::assertSame(45, array_sum(array_column($collection, 'copies')));
        self::assertArrayHasKey('name', $collection[0]);
    }
}
