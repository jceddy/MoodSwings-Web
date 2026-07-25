<?php

declare(strict_types=1);

namespace MoodSwings\Tests;

use MoodSwings\Notifications\NotificationChannel;
use MoodSwings\Notifications\NotificationScope;
use MoodSwings\Notifications\NotificationService;
use MoodSwings\Repository\NotificationCooldownRepository;
use MoodSwings\Repository\NotificationPreferenceRepository;
use MoodSwings\Repository\QueuedNotificationRepository;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * NotificationsIntegrationTest.php covers the shared preference/cooldown/
 * queue orchestration with a single (push) channel wired in --
 * DiscordNotificationChannelTest.php covers Discord's own channel-level
 * behavior. This file is the one place that actually exercises fanning
 * out to MORE THAN ONE channel at once, using bare in-memory
 * NotificationChannel stubs rather than real push/Discord ones, since
 * what's under test here is NotificationService::deliver()'s own
 * fan-out/failure-isolation logic, not any one channel's delivery
 * mechanics.
 */
final class NotificationServiceMultiChannelTest extends TestCase
{
    private PDO $pdo;
    private NotificationPreferenceRepository $preferences;
    private QueuedNotificationRepository $queuedNotifications;
    private NotificationCooldownRepository $cooldowns;

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
        $this->preferences = new NotificationPreferenceRepository();
        $this->queuedNotifications = new QueuedNotificationRepository();
        $this->cooldowns = new NotificationCooldownRepository();
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

    /** @param array<int, NotificationChannel> $channels */
    private function service(array $channels): NotificationService
    {
        return new NotificationService($this->preferences, $this->queuedNotifications, $this->cooldowns, $channels);
    }

    private function stubChannel(bool $delivers): NotificationChannel
    {
        return new class ($delivers) implements NotificationChannel {
            public int $calls = 0;

            public function __construct(private readonly bool $delivers)
            {
            }

            public function send(int $userId, array $payload): bool
            {
                $this->calls++;
                return $this->delivers;
            }
        };
    }

    private function throwingChannel(): NotificationChannel
    {
        return new class implements NotificationChannel {
            public function send(int $userId, array $payload): bool
            {
                throw new \RuntimeException('channel exploded');
            }
        };
    }

    public function testNotifyMarksCooldownWhenAtLeastOneOfSeveralChannelsDelivers(): void
    {
        $userId = $this->insertUser('multi-one-delivers');
        $deadChannel = $this->stubChannel(false);
        $liveChannel = $this->stubChannel(true);

        $this->service([$deadChannel, $liveChannel])->notifyYourTurn($userId, 1, 'waiting on you');

        self::assertSame(1, $deadChannel->calls, 'every channel must be tried, not short-circuited on the first');
        self::assertSame(1, $liveChannel->calls);
        self::assertTrue($this->cooldowns->wasNotifiedRecently($userId, NotificationScope::forGame(1), 300));
    }

    public function testNotifyDoesNotMarkCooldownWhenNoChannelDelivers(): void
    {
        $userId = $this->insertUser('multi-none-deliver');

        $this->service([$this->stubChannel(false), $this->stubChannel(false)])
            ->notifyYourTurn($userId, 2, 'waiting on you');

        self::assertFalse($this->cooldowns->wasNotifiedRecently($userId, NotificationScope::forGame(2), 300));
    }

    // A channel throwing (rather than returning false, as its own
    // contract asks) must not stop a different, still-working channel
    // from being tried, and must never escape notify() itself -- the
    // same fire-and-forget guarantee the class docblock promises.
    public function testOneChannelThrowingDoesNotPreventAnotherFromDeliveringOrEscapeNotify(): void
    {
        $userId = $this->insertUser('multi-one-throws');
        $liveChannel = $this->stubChannel(true);

        $this->service([$this->throwingChannel(), $liveChannel])->notifyYourTurn($userId, 3, 'waiting on you');

        self::assertSame(1, $liveChannel->calls);
        self::assertTrue($this->cooldowns->wasNotifiedRecently($userId, NotificationScope::forGame(3), 300));
    }

    public function testEveryChannelThrowingIsStillANoOpRatherThanThrowing(): void
    {
        $userId = $this->insertUser('multi-all-throw');

        $this->service([$this->throwingChannel(), $this->throwingChannel()])
            ->notifyYourTurn($userId, 4, 'waiting on you');

        self::addToAssertionCount(1); // reaching this line without throwing is the assertion
        self::assertFalse($this->cooldowns->wasNotifiedRecently($userId, NotificationScope::forGame(4), 300));
    }
}
