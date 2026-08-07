<?php

declare(strict_types=1);

namespace MoodSwings\Tests\Game;

use MoodSwings\Deck\UserDecklistService;
use MoodSwings\Friends\FriendshipService;
use MoodSwings\Game\BoardStateRepository;
use MoodSwings\Game\Exceptions\GameStateException;
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
 * Issue #140: practice bots. Exercises GameService::advanceBotTurns() (and
 * createGame()'s own bot-seating validation) end to end, against a real
 * database -- MoodSwings\Tests\Bot\BotChoiceResolverTest/
 * BotPlayerServiceTest already cover the choice-picking policy itself in
 * isolation, so these focus on the request-lifecycle wiring: does a bot's
 * turn actually get driven, does it stop at a real player, does a
 * pending decision targeting a bot get auto-answered, are bot games kept
 * out of lifetime/card stats.
 */
final class BotGameplayIntegrationTest extends TestCase
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
        $pdo->exec('TRUNCATE TABLE user_lifetime_stats');
        $pdo->exec('TRUNCATE TABLE card_stats');
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

    private function fetchRound(int $gameId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM game_rounds WHERE game_id = :game_id AND status = 'in_progress' ORDER BY round_number DESC LIMIT 1"
        );
        $stmt->execute(['game_id' => $gameId]);

        return $stmt->fetch();
    }

    // -- createGame() validation --------------------------------------

    public function testCreateGameRejectsABotInOpenTeamPlay(): void
    {
        $human = $this->insertUser('human1');
        $bot = $this->insertBotUser('bot1');
        $u2 = $this->insertUser('human2');
        $u3 = $this->insertUser('human3');

        $this->expectException(GameStateException::class);
        $this->games->createGame($human, [$human, $bot, $u2, $u3], format: 'team', partnerUserId: $bot);
    }

    public function testCreateGameRejectsABotWithACustomDeckType(): void
    {
        $human = $this->insertUser('human1');
        $bot = $this->insertBotUser('bot1');

        $this->expectException(GameStateException::class);
        $this->games->createGame($human, [$human, $bot], deckType: 'custom', decklistText: '');
    }

    public function testCreateGameAcceptsABotInStandardFormatWithAStructureDeck(): void
    {
        $human = $this->insertUser('human1');
        $bot = $this->insertBotUser('bot1');

        $gameId = $this->games->createGame($human, [$human, $bot], format: 'standard', deckType: 'structure');

        self::assertGreaterThan(0, $gameId);
    }

    // -- advanceBotTurns() ----------------------------------------------

    public function testAdvanceBotTurnsReturnsNullWhenNoBotsAreSeated(): void
    {
        $u1 = $this->insertUser('human1');
        $u2 = $this->insertUser('human2');
        $gameId = $this->insertGame('standard', 'structure', $u1);
        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $this->insertGamePlayer($gameId, $u2, 1);
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        self::assertNull($this->games->advanceBotTurns($gameId));
    }

    public function testBotPlaysItsHighestValuePlayableCardOnItsOwnTurn(): void
    {
        $u1 = $this->insertUser('human1');
        $botUserId = $this->insertBotUser('bot1');
        $gameId = $this->insertGame('standard', 'structure', $u1);
        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $botPlayerId = $this->insertGamePlayer($gameId, $botUserId, 1);

        // Dignity (id 8, value 3, has an optional field) and Apathy (id
        // 55, value 4, no ability at all) -- the bot should play Apathy,
        // the higher-value card, with no choices needed.
        $this->insertGameCard($gameId, 8, 'hand', $botPlayerId);
        $this->insertGameCard($gameId, 55, 'hand', $botPlayerId);
        $this->insertGameRound($gameId, 1, $botPlayerId, $botPlayerId, 1);

        $result = $this->games->advanceBotTurns($gameId);

        self::assertNotNull($result);
        self::assertTrue($this->cardIsInPlay($gameId, 55));
        self::assertTrue($this->cardIsInHand($gameId, 8)); // left alone -- lower value

        // Its one play spent, the turn should now be back with the human.
        $round = $this->fetchRound($gameId);
        self::assertSame($p1, (int) $round['current_turn_game_player_id']);
    }

    public function testBotPassesWhenItHasNothingPlayable(): void
    {
        $u1 = $this->insertUser('human1');
        $botUserId = $this->insertBotUser('bot1');
        $gameId = $this->insertGame('standard', 'structure', $u1);
        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $botPlayerId = $this->insertGamePlayer($gameId, $botUserId, 1);

        // Empty hand -- nothing at all playable, so the bot must pass.
        $this->insertGameRound($gameId, 1, $botPlayerId, $botPlayerId, 1);

        $result = $this->games->advanceBotTurns($gameId);

        self::assertNotNull($result);
        $round = $this->fetchRound($gameId);
        self::assertSame($p1, (int) $round['current_turn_game_player_id']);
    }

    public function testAdvanceBotTurnsStopsAtARealPlayersTurn(): void
    {
        $u1 = $this->insertUser('human1');
        $botUserId = $this->insertBotUser('bot1');
        $gameId = $this->insertGame('standard', 'structure', $u1);
        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $this->insertGamePlayer($gameId, $botUserId, 1);

        $this->insertGameCard($gameId, 8, 'hand', $p1);
        $this->insertGameRound($gameId, 1, $p1, $p1, 1); // it's the HUMAN's turn

        self::assertNull($this->games->advanceBotTurns($gameId));
        $round = $this->fetchRound($gameId);
        self::assertSame($p1, (int) $round['current_turn_game_player_id']); // untouched
    }

    public function testBotAutomaticallyAnswersAPendingDecisionTargetingIt(): void
    {
        $u1 = $this->insertUser('human1');
        $botUserId = $this->insertBotUser('bot1');
        $gameId = $this->insertGame('standard', 'structure', $u1);
        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $botPlayerId = $this->insertGamePlayer($gameId, $botUserId, 1);

        // Compulsion (id 86): "choose a player -- they must give up a
        // card from their hand." The bot needs at least one hand card
        // for a decision to even be created (CompulsionEffect declines
        // to ask at all if the target has nothing to give).
        $compulsionId = $this->insertGameCard($gameId, 86, 'hand', $p1);
        $this->insertGameCard($gameId, 8, 'hand', $botPlayerId);
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $result = $this->games->playMood($gameId, $p1, $compulsionId, ['target_player_id' => $botPlayerId]);
        self::assertTrue($result['pending_decision'] ?? false);

        // The route-handler-level wrapper this mirrors (see index.php)
        // calls this right after -- the bot should immediately answer
        // its own pending decision without anyone else acting.
        $botResult = $this->games->advanceBotTurns($gameId);
        self::assertNotNull($botResult);

        // Its only hand card is now the human's (moved over as Compulsion's
        // own effect), and nothing is left pending.
        self::assertTrue($this->cardIsInHand($gameId, 8, ownerUserId: $u1));
    }

    // -- stats exclusion --------------------------------------------------

    public function testCompletingAGameAgainstABotDoesNotBumpLifetimeStats(): void
    {
        $u1 = $this->insertUser('human1');
        $botUserId = $this->insertBotUser('bot1');
        $gameId = $this->insertGame('standard', 'structure', $u1);
        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $this->insertGamePlayer($gameId, $botUserId, 1);
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        // A 2-player game's own resignation completes the whole game
        // immediately (see GameService::resignGame()), crediting the bot
        // as the winner -- the simplest deterministic way to reach
        // recordGameCompletionStats() without playing a full round out.
        $this->games->resignGame($gameId, $p1);

        $stats = $this->games->lifetimeStatsFor($u1);
        self::assertSame(0, $stats['game_wins']);
        self::assertSame(0, $stats['game_losses']);
    }

    // -- helpers ------------------------------------------------------

    private function cardIsInPlay(int $gameId, int $catalogCardId): bool
    {
        $stmt = $this->pdo->prepare("SELECT 1 FROM game_cards WHERE game_id = :game_id AND card_id = :card_id AND zone = 'in_play'");
        $stmt->execute(['game_id' => $gameId, 'card_id' => $catalogCardId]);

        return $stmt->fetchColumn() !== false;
    }

    private function cardIsInHand(int $gameId, int $catalogCardId, ?int $ownerUserId = null): bool
    {
        if ($ownerUserId === null) {
            $stmt = $this->pdo->prepare("SELECT 1 FROM game_cards WHERE game_id = :game_id AND card_id = :card_id AND zone = 'hand'");
            $stmt->execute(['game_id' => $gameId, 'card_id' => $catalogCardId]);

            return $stmt->fetchColumn() !== false;
        }

        $stmt = $this->pdo->prepare(
            "SELECT 1 FROM game_cards gc JOIN game_players gp ON gp.id = gc.owner_game_player_id
             WHERE gc.game_id = :game_id AND gc.card_id = :card_id AND gc.zone = 'hand' AND gp.user_id = :user_id"
        );
        $stmt->execute(['game_id' => $gameId, 'card_id' => $catalogCardId, 'user_id' => $ownerUserId]);

        return $stmt->fetchColumn() !== false;
    }
}
