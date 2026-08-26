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
use MoodSwings\Rules\ChaosDefaultEffectRegistry;
use MoodSwings\Rules\DefaultEffectRegistry;
use MoodSwings\Rules\MoodPlayService;
use MoodSwings\Rules\RoundScorer;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * Chaos Draft (issue #405): chaos_015's own "at the beginning of each
 * round, if you go first this round, put a token into play" -- the one
 * effect wired through GameService::ensureChaosDraftOffersForRound()'s
 * own lazy "first offer of the round" gate (see that method's own
 * docblock) rather than MoodPlayService's per-play dispatch. Unlike
 * ChaosDraftOfferIntegrationTest.php (which only exercises the offer
 * mechanic itself and can get away with an empty ChaosEffectRegistry),
 * this needs the REAL registry so chaos_015's own implementation is
 * actually reachable.
 */
final class ChaosDraftRoundStartHookIntegrationTest extends TestCase
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
        $pdo->exec('TRUNCATE TABLE chaos_draft_offers');
        $pdo->exec('TRUNCATE TABLE game_events');
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
        $chaosRegistry = ChaosDefaultEffectRegistry::build();
        $userDecklists = new UserDecklistService(
            new UserDecklistRepository(),
            new FriendshipService(new UserRepository(), new FriendshipRepository()),
        );
        $this->games = new GameService(
            new BoardStateRepository($registry, $chaosRegistry),
            new MoodPlayService($registry, $chaosRegistry),
            new RoundScorer(),
            $userDecklists,
            new ReplayStateBuilder($registry),
            chaosRegistry: $chaosRegistry,
        );
    }

    /** @return array{gameId: int, player1: int, player2: int, chivalryGameCardId: int} */
    private function makeGameWithChivalryCarryingChaos015InPlayFor(int $ownerSeat, int $firstPlayerSeat): array
    {
        $this->pdo->prepare(
            "INSERT INTO users (id, username, email, password_hash, email_verified_at) VALUES
                (1, 'alice', 'alice@example.com', 'hash', NOW()),
                (2, 'bob', 'bob@example.com', 'hash', NOW())"
        )->execute();

        $this->pdo->prepare(
            "INSERT INTO games (id, format, deck_type, status, created_by_user_id) VALUES (1, 'draft', 'chaos_draft', 'in_progress', 1)"
        )->execute();

        $this->pdo->prepare(
            'INSERT INTO game_players (id, game_id, user_id, seat_order) VALUES (1, 1, 1, 1), (2, 1, 2, 2)'
        )->execute();

        $chaosEffectId = (int) $this->pdo->query("SELECT id FROM chaos_effects WHERE effect_key = 'chaos_015'")->fetchColumn();

        // Chivalry (catalog id 4) in play, carrying chaos_015, plus a
        // real hand card for each player so ensureChaosDraftOffersForRound()
        // actually creates offers (and, alongside them, fires the
        // round-start sweep) rather than skipping an empty-handed player.
        $this->pdo->prepare(
            'INSERT INTO game_cards (id, game_id, card_id, zone, owner_game_player_id, chaos_effect_id) VALUES (100, 1, 4, "in_play", :owner, :chaos_id)'
        )->execute(['owner' => $ownerSeat, 'chaos_id' => $chaosEffectId]);
        $this->pdo->prepare(
            'INSERT INTO game_cards (game_id, card_id, zone, owner_game_player_id) VALUES (1, 3, "hand", 1), (1, 3, "hand", 2)'
        )->execute();

        $this->pdo->prepare(
            "INSERT INTO game_rounds (game_id, round_number, first_game_player_id, current_turn_game_player_id, plays_remaining, pending_play_grants, status)
             VALUES (1, 1, :first, :first, 1, '[null]', 'in_progress')"
        )->execute(['first' => $firstPlayerSeat]);

        return ['gameId' => 1, 'player1' => 1, 'player2' => 2, 'chivalryGameCardId' => 100];
    }

    public function testChaos015SpawnsATokenAtRoundStartWhenItsOwnerGoesFirst(): void
    {
        $ids = $this->makeGameWithChivalryCarryingChaos015InPlayFor(ownerSeat: 1, firstPlayerSeat: 1);

        // The very first chaosDraftOfferFor() call for this round is what
        // lazily triggers "the beginning of the round" -- see
        // ensureChaosDraftOffersForRound()'s own docblock.
        $this->games->chaosDraftOfferFor($ids['gameId'], $ids['player1']);

        $tokenCount = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM game_cards WHERE game_id = {$ids['gameId']} AND card_id = 134 AND zone = 'in_play'"
        )->fetchColumn();
        self::assertSame(1, $tokenCount);
    }

    public function testChaos015DoesNotSpawnATokenWhenItsOwnerDidNotGoFirst(): void
    {
        // Chivalry (carrying chaos_015) belongs to player 2, but player 1 goes first.
        $ids = $this->makeGameWithChivalryCarryingChaos015InPlayFor(ownerSeat: 2, firstPlayerSeat: 1);

        $this->games->chaosDraftOfferFor($ids['gameId'], $ids['player1']);

        $tokenCount = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM game_cards WHERE game_id = {$ids['gameId']} AND card_id = 134"
        )->fetchColumn();
        self::assertSame(0, $tokenCount);
    }

    public function testChaos015OnlyFiresOnceEvenAcrossRepeatedOfferCallsTheSameRound(): void
    {
        $ids = $this->makeGameWithChivalryCarryingChaos015InPlayFor(ownerSeat: 1, firstPlayerSeat: 1);

        $this->games->chaosDraftOfferFor($ids['gameId'], $ids['player1']);
        $this->games->chaosDraftOfferFor($ids['gameId'], $ids['player1']);
        $this->games->chaosDraftOfferFor($ids['gameId'], $ids['player2']);

        $tokenCount = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM game_cards WHERE game_id = {$ids['gameId']} AND card_id = 134"
        )->fetchColumn();
        self::assertSame(1, $tokenCount);
    }
}
