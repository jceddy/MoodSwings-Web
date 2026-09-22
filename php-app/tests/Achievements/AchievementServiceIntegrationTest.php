<?php

declare(strict_types=1);

namespace MoodSwings\Tests\Achievements;

use MoodSwings\Achievements\AchievementService;
use MoodSwings\Database\Connection;
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
 * Exercises AchievementService purely through GameService's own public
 * API (resignGame(), same as GameServiceIntegrationTest's pattern),
 * confirming the recordGameCompletionStats() hook actually fires real
 * unlocks end to end -- not just that AchievementService's own methods
 * work in isolation.
 */
final class AchievementServiceIntegrationTest extends TestCase
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
        $pdo->exec('TRUNCATE TABLE game_round_scores');
        $pdo->exec('TRUNCATE TABLE game_cards');
        $pdo->exec('TRUNCATE TABLE game_rounds');
        $pdo->exec('TRUNCATE TABLE game_players');
        $pdo->exec('TRUNCATE TABLE games');
        $pdo->exec('TRUNCATE TABLE user_lifetime_stats');
        $pdo->exec('TRUNCATE TABLE card_stats');
        $pdo->exec('TRUNCATE TABLE user_achievements');
        $pdo->exec('TRUNCATE TABLE user_played_mythic_cards');
        $pdo->exec('TRUNCATE TABLE user_format_play_counts');
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

    private function insertGameRound(int $gameId, int $roundNumber, int $firstPlayerId, int $currentTurnPlayerId): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO game_rounds (game_id, round_number, first_game_player_id, current_turn_game_player_id, plays_remaining, status)
             VALUES (:game_id, :round_number, :first_player, :current_turn, 1, 'in_progress')"
        );
        $stmt->execute([
            'game_id' => $gameId,
            'round_number' => $roundNumber,
            'first_player' => $firstPlayerId,
            'current_turn' => $currentTurnPlayerId,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @return array{gameId:int, winnerUserId:int, loserUserId:int, winnerGamePlayerId:int} standard-format, 2-player, resigned immediately so the OTHER seat wins */
    private function playAndWinAStandardGame(string $winnerUsername, string $loserUsername, ?string $deckType = null): array
    {
        $winnerUserId = $this->insertUser($winnerUsername);
        $loserUserId = $this->insertUser($loserUsername);

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, deck_type, status, created_by_user_id, wins_needed) VALUES ('standard', :deck_type, 'in_progress', :created_by, 1)"
        );
        $stmt->execute(['deck_type' => $deckType ?? 'structure', 'created_by' => $winnerUserId]);
        $gameId = (int) $this->pdo->lastInsertId();

        $winnerGamePlayerId = $this->insertGamePlayer($gameId, $winnerUserId, 0);
        $loserGamePlayerId = $this->insertGamePlayer($gameId, $loserUserId, 1);
        $this->insertGameRound($gameId, 1, $winnerGamePlayerId, $winnerGamePlayerId);

        $this->games->resignGame($gameId, $loserGamePlayerId);

        return [
            'gameId' => $gameId,
            'winnerUserId' => $winnerUserId,
            'loserUserId' => $loserUserId,
            'winnerGamePlayerId' => $winnerGamePlayerId,
        ];
    }

    private function isUnlocked(int $userId, string $slug): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT ua.unlocked_at FROM user_achievements ua JOIN achievements a ON a.id = ua.achievement_id
             WHERE ua.user_id = :u AND a.slug = :slug'
        );
        $stmt->execute(['u' => $userId, 'slug' => $slug]);
        $value = $stmt->fetchColumn();

        return $value !== false && $value !== null;
    }

    private function progress(int $userId, string $slug): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT ua.progress FROM user_achievements ua JOIN achievements a ON a.id = ua.achievement_id
             WHERE ua.user_id = :u AND a.slug = :slug'
        );
        $stmt->execute(['u' => $userId, 'slug' => $slug]);
        $value = $stmt->fetchColumn();

        return $value === false ? 0 : (int) $value;
    }

    public function testWinningAGameUnlocksFirstStepsAndBumpsVolumeCounters(): void
    {
        $result = $this->playAndWinAStandardGame('achwin1', 'achlose1');

        self::assertTrue($this->isUnlocked($result['winnerUserId'], 'first-steps'));
        self::assertSame(1, $this->progress($result['winnerUserId'], 'getting-the-hang-of-it'));
        self::assertTrue($this->isUnlocked($result['loserUserId'], 'foot-in-the-door'), 'the loser still completed a game');
        self::assertFalse($this->isUnlocked($result['loserUserId'], 'first-steps'), 'the loser never won');
    }

    public function testWinningAStandardFormatGameBumpsTraditionalistNotOtherFormats(): void
    {
        $result = $this->playAndWinAStandardGame('achwin2', 'achlose2');

        self::assertSame(1, $this->progress($result['winnerUserId'], 'traditionalist'));
        self::assertSame(0, $this->progress($result['winnerUserId'], 'duelist'));
    }

    public function testWinningTenGamesUnlocksGettingTheHangOfIt(): void
    {
        $winnerUserId = $this->insertUser('achvolw');

        for ($i = 0; $i < 10; $i++) {
            $loserUserId = $this->insertUser("achvol{$i}l");

            $stmt = $this->pdo->prepare(
                "INSERT INTO games (format, deck_type, status, created_by_user_id, wins_needed) VALUES ('standard', 'structure', 'in_progress', :created_by, 1)"
            );
            $stmt->execute(['created_by' => $winnerUserId]);
            $gameId = (int) $this->pdo->lastInsertId();

            $winnerGamePlayerId = $this->insertGamePlayer($gameId, $winnerUserId, 0);
            $loserGamePlayerId = $this->insertGamePlayer($gameId, $loserUserId, 1);
            $this->insertGameRound($gameId, 1, $winnerGamePlayerId, $winnerGamePlayerId);
            $this->games->resignGame($gameId, $loserGamePlayerId);
        }

        self::assertTrue($this->isUnlocked($winnerUserId, 'getting-the-hang-of-it'));
    }

    public function testColorSynonymClusterUnlocksWhenAllFiveCardsAreInANonTraditionalDeck(): void
    {
        $winnerUserId = $this->insertUser('achsyn1');
        $loserUserId = $this->insertUser('achsyn2');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, deck_type, status, created_by_user_id, wins_needed) VALUES ('duel', 'structure', 'in_progress', :created_by, 1)"
        );
        $stmt->execute(['created_by' => $winnerUserId]);
        $gameId = (int) $this->pdo->lastInsertId();

        $winnerGamePlayerId = $this->insertGamePlayer($gameId, $winnerUserId, 0);
        $loserGamePlayerId = $this->insertGamePlayer($gameId, $loserUserId, 1);

        // Anger, Fury, Rage, Wrath -- Hulk Smash's cluster -- anywhere in the winner's deck (any zone).
        $this->insertGameCard($gameId, 80, 'hand', $winnerGamePlayerId);
        $this->insertGameCard($gameId, 91, 'deck', $winnerGamePlayerId);
        $this->insertGameCard($gameId, 98, 'discard', $winnerGamePlayerId);
        $this->insertGameCard($gameId, 105, 'in_play', $winnerGamePlayerId);

        $this->insertGameRound($gameId, 1, $winnerGamePlayerId, $winnerGamePlayerId);
        $this->games->resignGame($gameId, $loserGamePlayerId);

        self::assertTrue($this->isUnlocked($winnerUserId, 'hulk-smash'));
    }

    public function testColorSynonymClusterDoesNotUnlockInTraditionalFormat(): void
    {
        $winnerUserId = $this->insertUser('achsyn3');
        $loserUserId = $this->insertUser('achsyn4');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, deck_type, status, created_by_user_id, wins_needed) VALUES ('standard', 'structure', 'in_progress', :created_by, 1)"
        );
        $stmt->execute(['created_by' => $winnerUserId]);
        $gameId = (int) $this->pdo->lastInsertId();

        $winnerGamePlayerId = $this->insertGamePlayer($gameId, $winnerUserId, 0);
        $loserGamePlayerId = $this->insertGamePlayer($gameId, $loserUserId, 1);

        $this->insertGameCard($gameId, 80, 'hand', $winnerGamePlayerId);
        $this->insertGameCard($gameId, 91, 'deck', $winnerGamePlayerId);
        $this->insertGameCard($gameId, 98, 'discard', $winnerGamePlayerId);
        $this->insertGameCard($gameId, 105, 'in_play', $winnerGamePlayerId);

        $this->insertGameRound($gameId, 1, $winnerGamePlayerId, $winnerGamePlayerId);
        $this->games->resignGame($gameId, $loserGamePlayerId);

        self::assertFalse($this->isUnlocked($winnerUserId, 'hulk-smash'), 'Traditional format has no deliberate deck choice to reward');
    }

    public function testEmotionallyBalancedMetaUnlocksOnceAllFiveColorClustersAreUnlocked(): void
    {
        $winnerUserId = $this->insertUser('achmeta1');
        $loserUserId = $this->insertUser('achmeta2');

        $clusters = [
            ['good-samaritan', [1, 2, 3, 17]],
            ['spiral-of-dread', [28, 38, 52, 48, 46]],
            ['inconsolable', [65, 69, 70, 74]],
            ['hulk-smash', [80, 91, 98, 105]],
            ['walking-on-sunshine', [122, 125, 108, 111, 117]],
        ];

        foreach ($clusters as $i => [$slug, $catalogIds]) {
            $stmt = $this->pdo->prepare(
                "INSERT INTO games (format, deck_type, status, created_by_user_id, wins_needed) VALUES ('duel', 'structure', 'in_progress', :created_by, 1)"
            );
            $stmt->execute(['created_by' => $winnerUserId]);
            $gameId = (int) $this->pdo->lastInsertId();

            $winnerGamePlayerId = $this->insertGamePlayer($gameId, $winnerUserId, 0);
            $loserGamePlayerId = $this->insertGamePlayer($gameId, $loserUserId, 1);
            foreach ($catalogIds as $catalogId) {
                $this->insertGameCard($gameId, $catalogId, 'hand', $winnerGamePlayerId);
            }
            $this->insertGameRound($gameId, 1, $winnerGamePlayerId, $winnerGamePlayerId);
            $this->games->resignGame($gameId, $loserGamePlayerId);

            self::assertTrue($this->isUnlocked($winnerUserId, $slug), "cluster {$i} ({$slug}) should have unlocked");
        }

        self::assertTrue($this->isUnlocked($winnerUserId, 'emotionally-balanced'));
    }

    public function testMajorityColorFinalBoardBumpsSeeingRed(): void
    {
        $winnerUserId = $this->insertUser('achcolor1');
        $loserUserId = $this->insertUser('achcolor2');

        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, deck_type, status, created_by_user_id, wins_needed) VALUES ('standard', 'structure', 'in_progress', :created_by, 1)"
        );
        $stmt->execute(['created_by' => $winnerUserId]);
        $gameId = (int) $this->pdo->lastInsertId();

        $winnerGamePlayerId = $this->insertGamePlayer($gameId, $winnerUserId, 0);
        $loserGamePlayerId = $this->insertGamePlayer($gameId, $loserUserId, 1);

        // 3 red cards (Anger=80, Fury=91, Rage=98) + 1 white (Altruism=1) in play -- red is the majority.
        $this->insertGameCard($gameId, 80, 'in_play', $winnerGamePlayerId);
        $this->insertGameCard($gameId, 91, 'in_play', $winnerGamePlayerId);
        $this->insertGameCard($gameId, 98, 'in_play', $winnerGamePlayerId);
        $this->insertGameCard($gameId, 1, 'in_play', $winnerGamePlayerId);

        $this->insertGameRound($gameId, 1, $winnerGamePlayerId, $winnerGamePlayerId);
        $this->games->resignGame($gameId, $loserGamePlayerId);

        self::assertSame(1, $this->progress($winnerUserId, 'seeing-red'));
    }

    public function testTournamentCompletionUnlocksTournamentChampionAndBracketSpecificAchievement(): void
    {
        $winnerUserId = $this->insertUser('achtourn1');
        $achievements = new AchievementService();

        $achievements->onTournamentCompleted([
            'winner_user_id' => $winnerUserId,
            'bracket_type' => 'swiss',
            'registration_mode' => 'open',
            'match_params' => ['deck_type' => 'booster_draft'],
        ]);

        self::assertTrue($this->isUnlocked($winnerUserId, 'tournament-champion'));
        self::assertTrue($this->isUnlocked($winnerUserId, 'swiss-movement'));
        self::assertTrue($this->isUnlocked($winnerUserId, 'booster-buster'));
        self::assertTrue($this->isUnlocked($winnerUserId, 'open-door-policy'));
        self::assertSame(1, $this->progress($winnerUserId, 'grand-slam'));
    }

    public function testMoodRingUnlocksOnceOneAchievementFromEveryOtherCategoryIsUnlocked(): void
    {
        $userId = $this->insertUser('achmoodring1');
        $achievements = new AchievementService();

        // One representative slug per category A, B, C, D, E, F, G, I
        // (H itself is excluded -- Mood Ring is its own category's row).
        $onePerCategory = [
            'first-steps', 'traditionalist', 'quick-draw', 'rainbow-connection',
            'high-roller', 'bracketology', 'making-friends', 'vanilla-extract',
        ];
        foreach ($onePerCategory as $slug) {
            $achievements->unlock($userId, $slug);
        }

        self::assertTrue($this->isUnlocked($userId, 'mood-ring'));
    }

    public function testCompleteYourFirstGameCountsEvenForTheLoser(): void
    {
        $result = $this->playAndWinAStandardGame('achfoot1', 'achfoot2');

        self::assertTrue($this->isUnlocked($result['loserUserId'], 'foot-in-the-door'));
        self::assertSame(1, $this->progress($result['loserUserId'], 'regular'));
    }

    public function testCompletionistUnlocksOnceEveryOtherAchievementIsUnlocked(): void
    {
        $userId = $this->insertUser('achcompletionist1');
        $achievements = new AchievementService();

        $stmt = $this->pdo->prepare("SELECT slug FROM achievements WHERE slug != 'completionist'");
        $stmt->execute();
        foreach ($stmt->fetchAll() as $row) {
            $achievements->unlock($userId, $row['slug']);
        }

        self::assertTrue($this->isUnlocked($userId, 'completionist'));
    }
}
