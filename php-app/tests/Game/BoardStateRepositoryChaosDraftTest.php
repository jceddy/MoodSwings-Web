<?php

declare(strict_types=1);

namespace MoodSwings\Tests\Game;

use MoodSwings\Game\BoardStateRepository;
use MoodSwings\Rules\DefaultEffectRegistry;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * Chaos Draft (issue #405) stage 1's own persistence-layer proof: does
 * attaching a chaos effect to a card, and spawning a brand-new token
 * directly into play, both survive a real save()/load() round trip
 * against the actual game_cards table -- not just the pure in-memory
 * BoardState behavior ChaosDraftCompositionTest.php already covers. A
 * separate, narrowly-scoped file rather than folding into
 * GameServiceIntegrationTest.php's own much heavier full-game setup,
 * since this only needs a bare games/game_players/game_cards fixture,
 * built directly rather than through GameService::createGame().
 */
final class BoardStateRepositoryChaosDraftTest extends TestCase
{
    private PDO $pdo;
    private BoardStateRepository $repository;

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
        $this->repository = new BoardStateRepository(DefaultEffectRegistry::build());
    }

    /** @return array{gameId: int, player1: int, player2: int} */
    private function makeGame(): array
    {
        $this->pdo->prepare(
            "INSERT INTO users (id, username, email, password_hash, email_verified_at) VALUES
                (1, 'alice', 'alice@example.com', 'hash', NOW()),
                (2, 'bob', 'bob@example.com', 'hash', NOW())"
        )->execute();

        $this->pdo->prepare(
            "INSERT INTO games (id, format, status, created_by_user_id) VALUES (1, 'standard', 'in_progress', 1)"
        )->execute();

        $this->pdo->prepare(
            'INSERT INTO game_players (id, game_id, user_id, seat_order) VALUES (1, 1, 1, 1), (2, 1, 2, 2)'
        )->execute();

        return ['gameId' => 1, 'player1' => 1, 'player2' => 2];
    }

    private function insertHandCard(int $gameCardId, int $gameId, int $catalogCardId, int $ownerId): void
    {
        $this->pdo->prepare(
            'INSERT INTO game_cards (id, game_id, card_id, zone, owner_game_player_id) VALUES (:id, :game_id, :card_id, :zone, :owner)'
        )->execute([
            'id' => $gameCardId,
            'game_id' => $gameId,
            'card_id' => $catalogCardId,
            'zone' => 'hand',
            'owner' => $ownerId,
        ]);
    }

    public function testAttachedChaosEffectSurvivesASaveLoadRoundTrip(): void
    {
        ['gameId' => $gameId, 'player1' => $player1] = $this->makeGame();
        $this->insertHandCard(100, $gameId, 3, $player1); // Charity

        $state = $this->repository->load($gameId);
        self::assertNull($state->chaosEffectRow(100));

        $state->attachChaosEffect(100, 1); // chaos_001 (rare, after_playing)
        $this->repository->save($gameId, $state);

        $reloaded = $this->repository->load($gameId);
        self::assertSame(1, $reloaded->chaosEffectId(100));
        self::assertSame('chaos_001', $reloaded->chaosEffectKeyFor(100));
        self::assertSame('rare', $reloaded->chaosEffectRow(100)['rarity']);
    }

    public function testOverwritingAnAttachedChaosEffectPersistsTheNewOneOnly(): void
    {
        ['gameId' => $gameId, 'player1' => $player1] = $this->makeGame();
        $this->insertHandCard(100, $gameId, 3, $player1);

        $state = $this->repository->load($gameId);
        $state->attachChaosEffect(100, 1);
        $this->repository->save($gameId, $state);

        $state = $this->repository->load($gameId);
        $state->attachChaosEffect(100, 2); // chaos_002 (uncommon, after_playing)
        $this->repository->save($gameId, $state);

        $reloaded = $this->repository->load($gameId);
        self::assertSame(2, $reloaded->chaosEffectId(100));
    }

    public function testASpawnedTokenIsInsertedAsARealRowAndSurvivesReload(): void
    {
        ['gameId' => $gameId, 'player1' => $player1] = $this->makeGame();
        $this->insertHandCard(100, $gameId, 3, $player1); // an ordinary card, so game_cards isn't empty

        $state = $this->repository->load($gameId);
        $placeholderId = $state->spawnMoodInPlay(134, $player1); // Smugness
        self::assertLessThan(0, $placeholderId);

        $this->repository->save($gameId, $state);

        $rows = $this->pdo->query('SELECT * FROM game_cards WHERE game_id = 1 ORDER BY id')->fetchAll();
        self::assertCount(2, $rows);
        $spawnedRow = $rows[1];
        self::assertGreaterThan(0, (int) $spawnedRow['id']);
        self::assertSame(134, (int) $spawnedRow['card_id']);
        self::assertSame('in_play', $spawnedRow['zone']);
        self::assertSame($player1, (int) $spawnedRow['owner_game_player_id']);

        $reloaded = $this->repository->load($gameId);
        $realId = (int) $spawnedRow['id'];
        self::assertTrue($reloaded->isInPlay($realId));
        self::assertSame(1, $reloaded->valueOf($realId));
        self::assertSame('white', $reloaded->colorOf($realId));
    }

    public function testASpawnedTokenCarryingAChaosEffectPersistsItToo(): void
    {
        ['gameId' => $gameId, 'player1' => $player1] = $this->makeGame();
        $this->insertHandCard(100, $gameId, 3, $player1);

        $state = $this->repository->load($gameId);
        $placeholderId = $state->spawnMoodInPlay(137, $player1); // Tedium
        $state->attachChaosEffect($placeholderId, 3); // chaos_003 (common, after_playing)
        $this->repository->save($gameId, $state);

        $row = $this->pdo->query('SELECT * FROM game_cards WHERE card_id = 137')->fetch();
        self::assertNotFalse($row);
        self::assertSame(3, (int) $row['chaos_effect_id']);
    }

    /**
     * A real bug, reported live: GameService::finishPlay() saves once,
     * then (whenever the play ends the acting player's own turn --
     * the common case, not a rare one) advanceTurn() saves AGAIN against
     * the very same BoardState, to persist computeFreshGrants()'s own
     * side effect for the next player. A spawned token's own placeholder
     * id never changes in memory (see BoardState::spawnMoodInPlay()'s own
     * docblock), so before persistedCardIdFor()/recordSpawnedCardPersisted()
     * existed, save()'s own "negative id means insert a new row" check
     * couldn't tell "a token I already inserted earlier THIS SAME
     * request" apart from "a token I haven't inserted yet" -- a second
     * save() call blindly inserted a SECOND row for the same token. A
     * player who played Hate with a token-spawning chaos effect attached,
     * then played Creativity copying it, ended up with 4 tokens in play
     * instead of 2 (issue #405 follow-up) -- exactly two calls to
     * playMood(), each internally saving twice.
     */
    public function testSavingTheSameStateTwiceDoesNotDuplicateASpawnedToken(): void
    {
        ['gameId' => $gameId, 'player1' => $player1] = $this->makeGame();
        $this->insertHandCard(100, $gameId, 3, $player1);

        $state = $this->repository->load($gameId);
        $state->spawnMoodInPlay(136, $player1); // Passivity

        // Mirrors GameService::finishPlay() (saves once) immediately
        // followed by advanceTurn() (saves again for the SAME $state) --
        // nothing about the board changes between the two calls, the same
        // as a token spawn with no other board mutation before the turn
        // passes.
        $this->repository->save($gameId, $state);
        $this->repository->save($gameId, $state);

        $rows = $this->pdo->query("SELECT * FROM game_cards WHERE game_id = {$gameId} AND card_id = 136")->fetchAll();
        self::assertCount(1, $rows, 'the second save() should have UPDATEd the same row, not inserted a new one');

        $reloaded = $this->repository->load($gameId);
        self::assertCount(1, $reloaded->moodsInPlay());
        self::assertTrue($reloaded->isInPlay((int) $rows[0]['id']));
    }
}
