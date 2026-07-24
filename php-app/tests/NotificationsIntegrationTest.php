<?php

declare(strict_types=1);

namespace MoodSwings\Tests;

use MoodSwings\Notifications\PushNotificationService;
use MoodSwings\Repository\NotificationPreferenceRepository;
use MoodSwings\Repository\PushSubscriptionRepository;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

final class NotificationsIntegrationTest extends TestCase
{
    private PDO $pdo;
    private PushSubscriptionRepository $subscriptions;
    private NotificationPreferenceRepository $preferences;

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
        $pdo->exec('TRUNCATE TABLE push_subscriptions');
        $pdo->exec('TRUNCATE TABLE notification_preferences');
        $pdo->exec('TRUNCATE TABLE users');
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

        putenv("DB_HOST={$host}");
        putenv("DB_PORT={$port}");
        putenv("DB_NAME={$name}");
        putenv("DB_USER={$user}");
        putenv("DB_PASSWORD={$password}");

        $this->pdo = $pdo;
        $this->subscriptions = new PushSubscriptionRepository();
        $this->preferences = new NotificationPreferenceRepository();
    }

    protected function tearDown(): void
    {
        // Several tests below putenv() VAPID keys to exercise
        // PushNotificationService's "not configured" branch -- clear them
        // afterward so no other test file (running later in the same
        // PHPUnit process) inherits a stale value.
        putenv('VAPID_PUBLIC_KEY');
        putenv('VAPID_PRIVATE_KEY');
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

    public function testNotificationPreferencesDefaultToAllTrueUntilSaved(): void
    {
        $userId = $this->insertUser('prefs-default');

        self::assertSame(
            ['notify_your_turn' => true, 'notify_friend_request' => true, 'notify_game_finished' => true],
            $this->preferences->forUser($userId)
        );

        $this->preferences->save($userId, false, true, false);

        self::assertSame(
            ['notify_your_turn' => false, 'notify_friend_request' => true, 'notify_game_finished' => false],
            $this->preferences->forUser($userId)
        );
    }

    public function testNotificationPreferencesSaveIsUpsert(): void
    {
        $userId = $this->insertUser('prefs-upsert');

        $this->preferences->save($userId, false, false, false);
        $this->preferences->save($userId, true, true, true);

        self::assertSame(
            ['notify_your_turn' => true, 'notify_friend_request' => true, 'notify_game_finished' => true],
            $this->preferences->forUser($userId)
        );
    }

    public function testPushSubscriptionSaveListAndDelete(): void
    {
        $userId = $this->insertUser('sub-user');

        $this->subscriptions->save($userId, 'https://push.example.com/abc', 'p256dh-key', 'auth-key');

        $subscriptions = $this->subscriptions->listForUser($userId);
        self::assertCount(1, $subscriptions);
        self::assertSame('https://push.example.com/abc', $subscriptions[0]['endpoint']);
        self::assertSame('p256dh-key', $subscriptions[0]['p256dh_key']);
        self::assertSame('auth-key', $subscriptions[0]['auth_key']);

        $this->subscriptions->deleteByEndpoint($userId, 'https://push.example.com/abc');
        self::assertSame([], $this->subscriptions->listForUser($userId));
    }

    // Re-subscribing the same browser (endpoint unchanged, keys rotated)
    // updates the existing row instead of accumulating a duplicate -- see
    // migration 0048's own docblock on why uniqueness is keyed on
    // endpoint_hash rather than left to accumulate.
    public function testSavingTheSameEndpointTwiceUpsertsRatherThanDuplicates(): void
    {
        $userId = $this->insertUser('sub-upsert-user');

        $this->subscriptions->save($userId, 'https://push.example.com/xyz', 'old-p256dh', 'old-auth');
        $this->subscriptions->save($userId, 'https://push.example.com/xyz', 'new-p256dh', 'new-auth');

        $subscriptions = $this->subscriptions->listForUser($userId);
        self::assertCount(1, $subscriptions);
        self::assertSame('new-p256dh', $subscriptions[0]['p256dh_key']);
        self::assertSame('new-auth', $subscriptions[0]['auth_key']);
    }

    public function testDeleteByIdRemovesOnlyThatSubscription(): void
    {
        $userId = $this->insertUser('sub-delete-by-id');

        $this->subscriptions->save($userId, 'https://push.example.com/one', 'p1', 'a1');
        $this->subscriptions->save($userId, 'https://push.example.com/two', 'p2', 'a2');

        $subscriptions = $this->subscriptions->listForUser($userId);
        $firstId = $subscriptions[0]['endpoint'] === 'https://push.example.com/one' ? $subscriptions[0]['id'] : $subscriptions[1]['id'];

        $this->subscriptions->deleteById($firstId);

        $remaining = $this->subscriptions->listForUser($userId);
        self::assertCount(1, $remaining);
        self::assertSame('https://push.example.com/two', $remaining[0]['endpoint']);
    }

    // PushNotificationService's notify() must never attempt a network call
    // (and therefore never throw) when there's nothing to send to -- these
    // cases are all resolved from the database alone, before WebPush is
    // ever constructed, so they're safe to assert without a real push
    // service or configured VAPID keys.
    public function testNotifyIsANoOpWhenTheUserHasNoSubscriptions(): void
    {
        $userId = $this->insertUser('no-subscriptions');
        putenv('VAPID_PUBLIC_KEY=some-public-key');
        putenv('VAPID_PRIVATE_KEY=some-private-key');

        $service = new PushNotificationService($this->subscriptions, $this->preferences);
        $service->notifyYourTurn($userId, 1, 'Game #1 is waiting on your move.');

        self::addToAssertionCount(1); // reaching this line without throwing is the assertion
    }

    public function testNotifyIsANoOpWhenThePreferenceIsDisabled(): void
    {
        $userId = $this->insertUser('pref-disabled');
        $this->subscriptions->save($userId, 'https://push.example.com/disabled', 'p', 'a');
        $this->preferences->save($userId, false, true, true);
        putenv('VAPID_PUBLIC_KEY=some-public-key');
        putenv('VAPID_PRIVATE_KEY=some-private-key');

        $service = new PushNotificationService($this->subscriptions, $this->preferences);
        // notify_your_turn is off -- even though a subscription exists,
        // this must return before ever touching the network.
        $service->notifyYourTurn($userId, 1, 'Game #1 is waiting on your move.');

        self::addToAssertionCount(1);
    }

    public function testNotifyIsANoOpWhenVapidKeysAreNotConfigured(): void
    {
        $userId = $this->insertUser('no-vapid-keys');
        $this->subscriptions->save($userId, 'https://push.example.com/no-vapid', 'p', 'a');
        putenv('VAPID_PUBLIC_KEY=');
        putenv('VAPID_PRIVATE_KEY=');

        $service = new PushNotificationService($this->subscriptions, $this->preferences);
        $service->notifyGameFinished($userId, 1, 'Game #1 is over -- you won!');

        self::addToAssertionCount(1);
    }
}
