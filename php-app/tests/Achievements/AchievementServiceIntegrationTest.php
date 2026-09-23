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
use MoodSwings\Notifications\NotificationChannel;
use MoodSwings\Notifications\NotificationService;
use MoodSwings\Repository\FriendshipRepository;
use MoodSwings\Repository\NotificationCooldownRepository;
use MoodSwings\Repository\NotificationPreferenceRepository;
use MoodSwings\Repository\QueuedNotificationRepository;
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
        $pdo->exec('TRUNCATE TABLE user_daily_game_counts');
        $pdo->exec('TRUNCATE TABLE user_opponent_game_counts');
        $pdo->exec('TRUNCATE TABLE notification_preferences');
        $pdo->exec('TRUNCATE TABLE notification_cooldowns');
        $pdo->exec('TRUNCATE TABLE queued_notifications');
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

    private function setTimezone(int $userId, string $timezone): void
    {
        $this->pdo->prepare('UPDATE users SET timezone = :tz WHERE id = :u')
            ->execute(['tz' => $timezone, 'u' => $userId]);
    }

    /**
     * A game row/pair of game_players good enough for onGameCompleted()
     * to run against directly (no game_cards/game_rounds seeded, so
     * checkColorAndCardFeats()/checkInGameSkillFeats() just no-op on
     * their own empty-result guards) -- used instead of
     * playAndWinAStandardGame()/resignGame() by the Night Owl/Early Bird
     * tests below, which need a specific completed_at rather than
     * whatever NOW() happens to be when the test runs.
     *
     * @return array{gameId:int}
     */
    private function insertMinimalCompletedGame(int $winnerUserId, int $loserUserId, string $completedAtUtc): array
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, deck_type, status, created_by_user_id, wins_needed, completed_at)
             VALUES ('standard', 'structure', 'completed', :created_by, 1, :completed_at)"
        );
        $stmt->execute(['created_by' => $winnerUserId, 'completed_at' => $completedAtUtc]);
        $gameId = (int) $this->pdo->lastInsertId();

        $this->insertGamePlayer($gameId, $winnerUserId, 0);
        $this->insertGamePlayer($gameId, $loserUserId, 1);

        return ['gameId' => $gameId];
    }

    /** @return array<string, mixed> minimal onGameCompleted() $game shape -- see insertMinimalCompletedGame() */
    private function minimalGameArray(string $completedAtUtc): array
    {
        return [
            'format' => 'standard',
            'deck_type' => 'structure',
            'default_selections_mode' => 0,
            'timeout_minutes' => null,
            'total_time_limit_minutes' => null,
            'completed_at' => $completedAtUtc,
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

    public function testRematchUnlocksAfterFiveGamesAgainstTheSameOpponent(): void
    {
        $userAId = $this->insertUser('achrematchA');
        $userBId = $this->insertUser('achrematchB');

        for ($i = 0; $i < 5; $i++) {
            $stmt = $this->pdo->prepare(
                "INSERT INTO games (format, deck_type, status, created_by_user_id, wins_needed) VALUES ('standard', 'structure', 'in_progress', :created_by, 1)"
            );
            $stmt->execute(['created_by' => $userAId]);
            $gameId = (int) $this->pdo->lastInsertId();

            $aGamePlayerId = $this->insertGamePlayer($gameId, $userAId, 0);
            $bGamePlayerId = $this->insertGamePlayer($gameId, $userBId, 1);
            $this->insertGameRound($gameId, 1, $aGamePlayerId, $aGamePlayerId);
            $this->games->resignGame($gameId, $bGamePlayerId);
        }

        self::assertTrue($this->isUnlocked($userAId, 'rematch'));
        self::assertTrue($this->isUnlocked($userBId, 'rematch'), 'the losing side played the same 5 games too');
    }

    public function testMarathonSessionUnlocksAfterThreeGamesCompletedTheSameDay(): void
    {
        $winnerUserId = $this->insertUser('achmarathonw');

        for ($i = 0; $i < 3; $i++) {
            $loserUserId = $this->insertUser("achmarathonl{$i}");
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

        self::assertTrue($this->isUnlocked($winnerUserId, 'marathon-session'));
    }

    /**
     * Reported live: "the timed achievements aren't calculating local
     * time correctly" -- Night Owl/Early Bird's own catalog descriptions
     * both say "your local time" explicitly, so the SAME completed_at
     * moment must unlock differently depending on which timezone the
     * winner's own browser last reported (users.timezone), not read a
     * single shared UTC hour off the game row for every winner.
     */
    public function testNightOwlAndEarlyBirdAreEvaluatedPerPlayerTimezoneNotServerUtc(): void
    {
        $completedAtUtc = '2026-01-01 09:00:00'; // 9am UTC

        // 9am UTC is 1am in Los Angeles (UTC-8 in January) -- Night Owl's
        // own midnight-4am window.
        $laWinnerId = $this->insertUser('achtzla');
        $laLoserId = $this->insertUser('achtzlal');
        $this->setTimezone($laWinnerId, 'America/Los_Angeles');

        // The exact same 9am UTC is 10am in Berlin (UTC+1 in January) --
        // nowhere near either window.
        $berlinWinnerId = $this->insertUser('achtzberlin');
        $berlinLoserId = $this->insertUser('achtzberlinl');
        $this->setTimezone($berlinWinnerId, 'Europe/Berlin');

        $achievements = new AchievementService();
        $game = $this->minimalGameArray($completedAtUtc);

        ['gameId' => $laGameId] = $this->insertMinimalCompletedGame($laWinnerId, $laLoserId, $completedAtUtc);
        $achievements->onGameCompleted($laGameId, $game, [$laWinnerId], [$laLoserId], false);

        ['gameId' => $berlinGameId] = $this->insertMinimalCompletedGame($berlinWinnerId, $berlinLoserId, $completedAtUtc);
        $achievements->onGameCompleted($berlinGameId, $game, [$berlinWinnerId], [$berlinLoserId], false);

        self::assertTrue($this->isUnlocked($laWinnerId, 'night-owl'), 'the LA winner is at 1am local time');
        self::assertFalse($this->isUnlocked($laWinnerId, 'early-bird'));
        self::assertFalse($this->isUnlocked($berlinWinnerId, 'night-owl'), 'the Berlin winner is at 10am local time');
        self::assertFalse($this->isUnlocked($berlinWinnerId, 'early-bird'));
    }

    /**
     * A user whose browser has never sent an X-Timezone header yet (never
     * logged in since this shipped) has users.timezone still NULL --
     * AchievementService::timezoneFor() falls back to UTC for them,
     * matching this feature's pre-fix behavior rather than throwing or
     * silently skipping the check.
     */
    public function testNightOwlFallsBackToUtcWhenThePlayersTimezoneIsUnknown(): void
    {
        $winnerUserId = $this->insertUser('achtzunknown');
        $loserUserId = $this->insertUser('achtzunknownl');

        $completedAtUtc = '2026-01-01 02:00:00';
        ['gameId' => $gameId] = $this->insertMinimalCompletedGame($winnerUserId, $loserUserId, $completedAtUtc);

        (new AchievementService())->onGameCompleted(
            $gameId,
            $this->minimalGameArray($completedAtUtc),
            [$winnerUserId],
            [$loserUserId],
            false
        );

        self::assertTrue($this->isUnlocked($winnerUserId, 'night-owl'));
    }

    /**
     * Marathon Session's own catalog wording ("a single calendar day")
     * means the PLAYER's day, the same "your local time" intent as Night
     * Owl/Early Bird above -- checkMarathonSession() computes play_date
     * from each user's own timezone rather than CURDATE(), which reflects
     * the DB session's fixed UTC. Proven with UTC+14 and UTC-12 (a
     * 26-hour gap): at ANY real instant this test happens to run, at
     * least one of them has already crossed into a different calendar
     * date than the other -- see the exact hour-by-hour reasoning this
     * relies on in AchievementService::checkMarathonSession()'s own
     * comment -- so two distinct play_date rows for the exact same real
     * moment is only possible if each is genuinely computed from that
     * specific player's own timezone, never a single shared server value.
     */
    public function testMarathonSessionUsesEachPlayersOwnCalendarDayNotServerUtc(): void
    {
        $farEastUserId = $this->insertUser('achtzfareast');
        $farWestUserId = $this->insertUser('achtzfarwest');
        $this->setTimezone($farEastUserId, 'Pacific/Kiritimati'); // UTC+14
        $this->setTimezone($farWestUserId, 'Etc/GMT+12'); // UTC-12 (POSIX sign convention)

        $achievements = new AchievementService();
        foreach ([$farEastUserId, $farWestUserId] as $userId) {
            $loserUserId = $this->insertUser("achtzopp{$userId}");
            $completedAtUtc = gmdate('Y-m-d H:i:s');
            ['gameId' => $gameId] = $this->insertMinimalCompletedGame($userId, $loserUserId, $completedAtUtc);
            $achievements->onGameCompleted($gameId, $this->minimalGameArray($completedAtUtc), [$userId], [$loserUserId], false);
        }

        $stmt = $this->pdo->prepare('SELECT play_date FROM user_daily_game_counts WHERE user_id = :u');
        $stmt->execute(['u' => $farEastUserId]);
        $farEastDate = $stmt->fetchColumn();
        $stmt->execute(['u' => $farWestUserId]);
        $farWestDate = $stmt->fetchColumn();

        self::assertNotFalse($farEastDate);
        self::assertNotFalse($farWestDate);
        self::assertNotSame($farWestDate, $farEastDate);
    }

    public function testBotWranglerUnlocksWhenCreatingAGameWithTwoOrMoreBots(): void
    {
        $humanUserId = $this->insertUser('achbotwrangler1');
        $bot1UserId = $this->insertUser('achbot1');
        $bot2UserId = $this->insertUser('achbot2');
        $this->pdo->prepare('UPDATE users SET is_bot = 1 WHERE id IN (:b1, :b2)')
            ->execute(['b1' => $bot1UserId, 'b2' => $bot2UserId]);

        $gameId = $this->games->createGame(
            $humanUserId,
            [$humanUserId, $bot1UserId, $bot2UserId],
        );
        self::assertGreaterThan(0, $gameId);

        self::assertTrue($this->isUnlocked($humanUserId, 'bot-wrangler'));
    }

    public function testUnlockingAnAchievementDeliversANotification(): void
    {
        $userId = $this->insertUser('achnotify1');

        $channel = new class implements NotificationChannel {
            /** @var array<int, array{userId:int, payload:array}> */
            public array $sent = [];

            public function send(int $userId, array $payload): bool
            {
                $this->sent[] = ['userId' => $userId, 'payload' => $payload];

                return true;
            }
        };
        $notifications = new NotificationService(
            new NotificationPreferenceRepository(),
            new QueuedNotificationRepository(),
            new NotificationCooldownRepository(),
            [$channel],
        );
        $achievements = new AchievementService($notifications);

        $achievements->unlock($userId, 'first-steps');

        self::assertCount(1, $channel->sent);
        self::assertSame($userId, $channel->sent[0]['userId']);
        self::assertStringContainsString('First Steps', $channel->sent[0]['payload']['body']);
    }

    public function testAchievementNotificationsRespectTheOptOutPreference(): void
    {
        $userId = $this->insertUser('achnotify2');
        (new NotificationPreferenceRepository())->save($userId, true, true, true, false, true, true, false);

        $channel = new class implements NotificationChannel {
            public int $calls = 0;

            public function send(int $userId, array $payload): bool
            {
                $this->calls++;

                return true;
            }
        };
        $notifications = new NotificationService(
            new NotificationPreferenceRepository(),
            new QueuedNotificationRepository(),
            new NotificationCooldownRepository(),
            [$channel],
        );
        $achievements = new AchievementService($notifications);

        $achievements->unlock($userId, 'first-steps');

        self::assertSame(0, $channel->calls);
    }

    public function testCatalogForUserRedactsHiddenAchievementsUntilUnlockedAndRevealsThemAfter(): void
    {
        $userId = $this->insertUser('achcatalog1');
        $achievements = new AchievementService();

        $catalog = $achievements->catalogForUser($userId);
        self::assertArrayHasKey('H', $catalog);
        $moodRing = self::findBySlug($catalog['H'], 'mood-ring');
        self::assertSame('???', $moodRing['title']);
        self::assertFalse($moodRing['unlocked_at'] !== null);

        $achievements->unlock($userId, 'mood-ring');

        $catalogAfter = $achievements->catalogForUser($userId);
        $moodRingAfter = self::findBySlug($catalogAfter['H'], 'mood-ring');
        self::assertSame('Mood Ring', $moodRingAfter['title']);
        self::assertNotNull($moodRingAfter['unlocked_at']);
    }

    public function testCatalogForUserReflectsRealProgressForACounterAchievement(): void
    {
        $result = $this->playAndWinAStandardGame('achcatalog2', 'achcatalog2l');
        $achievements = new AchievementService();

        $catalog = $achievements->catalogForUser($result['winnerUserId']);
        $gettingTheHang = self::findBySlug($catalog['A'], 'getting-the-hang-of-it');
        self::assertSame(1, $gettingTheHang['progress']);
        self::assertSame(10, $gettingTheHang['target']);
        self::assertNull($gettingTheHang['unlocked_at']);
    }

    /** @param array<int, array<string, mixed>> $rows */
    private static function findBySlug(array $rows, string $slug): array
    {
        foreach ($rows as $row) {
            if ($row['slug'] === $slug) {
                return $row;
            }
        }

        self::fail("no achievement with slug {$slug} found");
    }
}
