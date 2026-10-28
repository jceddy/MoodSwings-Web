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
    private GameService $games;
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
        $this->games = $games = new GameService(
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

    /** The granted structure deck is saved as a private decklist on a player's first join. */
    private function grantedDeckId(int $userId): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM user_decklists WHERE user_id = :user_id ORDER BY id LIMIT 1');
        $stmt->execute(['user_id' => $userId]);
        $id = $stmt->fetchColumn();
        self::assertNotFalse($id, 'the first join should have saved the granted deck');

        return (int) $id;
    }

    private function firstJoin(int $userId): array
    {
        return $this->league->joinAndQueue($userId);
    }

    private function gamePlayerId(int $gameId, int $userId): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM game_players WHERE game_id = :game_id AND user_id = :user_id');
        $stmt->execute(['game_id' => $gameId, 'user_id' => $userId]);

        return (int) $stmt->fetchColumn();
    }

    /** Submits a saved decklist as $userId's deck for the League game, as the in-game builder does. */
    private function submitDeck(int $gameId, int $userId, int $savedDecklistId): void
    {
        $this->games->submitCustomDuelDeck($gameId, $this->gamePlayerId($gameId, $userId), null, $savedDecklistId, $userId);
    }

    private function startEvent(): int
    {
        return $this->events->start('Test League', 'TST');
    }

    public function testJoiningWithNoActiveEventIsRejected(): void
    {
        $userId = $this->insertUser('alice');

        $this->expectException(GameStateException::class);
        $this->league->joinAndQueue($userId);
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
                $this->league->joinAndQueue($userId);
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
        $this->league->joinAndQueue($alice);
        self::assertSame(45, $this->league->statusFor($alice)['collection']['total_cards']);
    }

    public function testASecondPlayerIsPairedIntoABestOfThreeDuelThatWaitsForDecks(): void
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
        self::assertSame('waiting', $game['status'], 'no deck is seeded: each player chooses theirs in the game');
        self::assertNotNull($game['game_match_id']);
        self::assertSame(12, (int) $game['custom_duel_min_cards']);
        self::assertEquals(['mythic' => 2, 'rare' => 6], json_decode((string) $game['custom_duel_rarity_limits'], true));

        $seats = $this->pdo->query('SELECT custom_deck_card_ids, custom_deck_allowed_card_ids FROM game_players WHERE game_id = ' . (int) $paired['game_id'])->fetchAll();
        self::assertCount(2, $seats);
        foreach ($seats as $seat) {
            self::assertNull($seat['custom_deck_card_ids']);
            self::assertCount(45, json_decode((string) $seat['custom_deck_allowed_card_ids'], true), 'each seat may only use its own collection');
        }

        self::assertSame(1, $this->league->statusFor($alice)['in_progress_count']);
        self::assertFalse($this->league->statusFor($alice)['queued']);
        self::assertSame('in_progress', $this->league->statusFor($bob)['matches'][0]['status']);
    }

    public function testTheGameStateOffersTheCollectionAsADeckBuilderStartingFromTheGrantedDeck(): void
    {
        $this->startEvent();
        $alice = $this->insertUser('alice');
        $bob = $this->insertUser('bob');
        $this->firstJoin($alice);
        $gameId = $this->firstJoin($bob)['game_id'];

        $builder = $this->games->getState($gameId, $alice)['league_deck_builder'];

        self::assertNotNull($builder);
        self::assertSame(12, $builder['min_cards']);
        self::assertEquals(['mythic' => 2, 'rare' => 6], $builder['rarity_limits']);
        self::assertSame(45, array_sum(array_column($builder['pool'], 'copies')));
        self::assertArrayHasKey('rules_text', $builder['pool'][0]);

        $granted = array_map('intval', (array) json_decode((string) $this->pdo->query("SELECT granted_card_ids FROM league_participants WHERE user_id = {$alice}")->fetchColumn(), true));
        self::assertEquals($granted, $builder['default_deck_card_ids']);

        // Each player sees only their own pool, and an ordinary game has no builder at all.
        $bobBuilder = $this->games->getState($gameId, $bob)['league_deck_builder'];
        self::assertNotEquals($builder['default_deck_card_ids'], $bobBuilder['default_deck_card_ids']);
    }

    public function testBothPlayersSubmittingFromTheirCollectionsStartsTheMatch(): void
    {
        $this->startEvent();
        $alice = $this->insertUser('alice');
        $bob = $this->insertUser('bob');
        $this->firstJoin($alice);
        $gameId = $this->firstJoin($bob)['game_id'];

        $this->submitDeck($gameId, $alice, $this->grantedDeckId($alice));
        $this->submitDeck($gameId, $bob, $this->grantedDeckId($bob));
        $this->games->startGame($gameId);

        self::assertSame('in_progress', $this->pdo->query("SELECT status FROM games WHERE id = {$gameId}")->fetchColumn());
        self::assertNull($this->games->getState($gameId, $alice)['league_deck_builder'], 'the builder is only offered while the game waits for decks');
    }

    public function testPlayersNeverFaceTheSameOpponentTwiceInOneEvent(): void
    {
        $this->startEvent();
        $alice = $this->insertUser('alice');
        $bob = $this->insertUser('bob');

        $this->firstJoin($alice);
        $this->firstJoin($bob);

        // Both queue again; they've already played each other, so neither is paired.
        self::assertSame('waiting', $this->league->joinAndQueue($alice)['status']);
        self::assertSame('waiting', $this->league->joinAndQueue($bob)['status']);

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
        $this->league->joinAndQueue($alice);
        $this->firstJoin($carol);
        self::assertSame(LeagueService::CONCURRENT_MATCH_CAP, $this->league->statusFor($alice)['in_progress_count']);

        $this->expectException(GameStateException::class);
        $this->expectExceptionMessage('in progress');
        $this->league->joinAndQueue($alice);
    }

    public function testSubmittedDecksMustFitTheCollectionAndTheLeagueRules(): void
    {
        $this->startEvent();
        $alice = $this->insertUser('alice');
        $bob = $this->insertUser('bob');
        $this->firstJoin($alice);
        $gameId = $this->firstJoin($bob)['game_id'];

        $granted = array_map('intval', (array) json_decode((string) $this->pdo->query("SELECT granted_card_ids FROM league_participants WHERE user_id = {$alice}")->fetchColumn(), true));

        // A card that was never granted.
        $unowned = (int) $this->pdo->query(
            'SELECT id FROM cards WHERE is_token = 0 AND id NOT IN (' . implode(',', $granted) . ") AND rarity = 'common' LIMIT 1"
        )->fetchColumn();
        $deckWithUnowned = $this->decklists->create($alice, 'Unowned', array_merge(array_slice($granted, 0, 11), [$unowned]), null, 'private');
        try {
            $this->submitDeck($gameId, $alice, $deckWithUnowned);
            self::fail('a card outside the collection should be refused');
        } catch (GameStateException $e) {
            self::assertStringContainsString('not in your own', $e->getMessage());
        }

        // Too many copies of a card that was granted once.
        $twice = $this->decklists->create($alice, 'Twice', array_merge(array_slice($granted, 0, 11), [$granted[0]]), null, 'private');
        try {
            $this->submitDeck($gameId, $alice, $twice);
            self::fail('more copies than owned should be refused');
        } catch (GameStateException $e) {
            self::assertStringContainsString('not in your own', $e->getMessage());
        }

        // Fewer than 12 cards.
        $small = $this->decklists->create($alice, 'Small', array_slice($granted, 0, 11), null, 'private');
        try {
            $this->submitDeck($gameId, $alice, $small);
            self::fail('an undersized deck should be refused');
        } catch (GameStateException $e) {
            self::assertStringContainsString('at least 12', $e->getMessage());
        }

        // Exactly 12 owned cards is legal.
        $legal = $this->decklists->create($alice, 'Legal', array_slice($granted, 0, 12), null, 'private');
        $this->submitDeck($gameId, $alice, $legal);
        self::assertCount(12, json_decode((string) $this->pdo->query('SELECT custom_deck_card_ids FROM game_players WHERE id = ' . $this->gamePlayerId($gameId, $alice))->fetchColumn(), true));
    }

    public function testMythicAndRareCapsApplyEvenWhenOwned(): void
    {
        $this->startEvent();
        $alice = $this->insertUser('alice');
        $bob = $this->insertUser('bob');

        // Give alice plenty of mythics before the pairing snapshots her collection, so only the cap can stop a deck with three.
        $this->firstJoin($alice);
        $this->league->leaveQueue($alice);
        $mythics = array_map('intval', $this->pdo->query("SELECT id FROM cards WHERE rarity = 'mythic' AND is_token = 0 LIMIT 3")->fetchAll(PDO::FETCH_COLUMN));
        foreach ($mythics as $cardId) {
            $this->pdo->exec("INSERT INTO user_collection_cards (user_id, card_id, copies) VALUES ({$alice}, {$cardId}, 1) ON DUPLICATE KEY UPDATE copies = GREATEST(copies, 1)");
        }
        $commons = array_map('intval', $this->pdo->query("SELECT card_id FROM user_collection_cards c JOIN cards k ON k.id = c.card_id WHERE c.user_id = {$alice} AND k.rarity = 'common' LIMIT 9")->fetchAll(PDO::FETCH_COLUMN));

        $this->league->joinAndQueue($alice);
        $gameId = $this->firstJoin($bob)['game_id'];

        $threeMythics = $this->decklists->create($alice, 'Three mythics', array_merge($mythics, $commons), null, 'private');
        $this->expectException(GameStateException::class);
        $this->expectExceptionMessage('at most 2 mythic');
        $this->submitDeck($gameId, $alice, $threeMythics);
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
        $this->league->joinAndQueue($alice);
        $this->league->leaveQueue($alice);
        $this->pdo->exec("UPDATE league_matches SET status = 'abandoned'");
        self::assertSame('waiting', $this->league->joinAndQueue($alice)['status']);
        self::assertSame('paired', $this->league->joinAndQueue($bob)['status']);
    }

    public function testCollectionListsOwnedCardsWithCopiesInColorRarityNameOrder(): void
    {
        $this->startEvent();
        $alice = $this->insertUser('alice');
        $this->firstJoin($alice);

        $collection = $this->league->collectionFor($alice);

        self::assertSame(45, array_sum(array_column($collection, 'copies')));
        self::assertArrayHasKey('rules_text', $collection[0], 'full catalog cards, so the client can draw thumbnails and the detail view');
        self::assertArrayHasKey('card_id', $collection[0]);

        $colorRank = array_flip(['white', 'blue', 'black', 'red', 'green']);
        $rarityRank = array_flip(['common', 'uncommon', 'rare', 'mythic']);
        $keys = array_map(static fn (array $c): array => [$colorRank[$c['color']], $rarityRank[$c['rarity']], $c['name']], $collection);
        $sorted = $keys;
        usort($sorted, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]] ?: strcmp($a[2], $b[2]));
        self::assertSame($sorted, $keys);
    }
}
