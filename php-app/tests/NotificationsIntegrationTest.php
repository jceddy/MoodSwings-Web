<?php

declare(strict_types=1);

namespace MoodSwings\Tests;

use MoodSwings\Notifications\PushNotificationService;
use MoodSwings\Repository\NotificationPreferenceRepository;
use MoodSwings\Repository\PushSubscriptionRepository;
use MoodSwings\Repository\QueuedNotificationRepository;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

final class NotificationsIntegrationTest extends TestCase
{
    private PDO $pdo;
    private PushSubscriptionRepository $subscriptions;
    private NotificationPreferenceRepository $preferences;
    private QueuedNotificationRepository $queuedNotifications;

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
        $pdo->exec('TRUNCATE TABLE queued_notifications');
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
        $this->queuedNotifications = new QueuedNotificationRepository();
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

    // enqueue() always stamps queued_at = NOW(), so tests that need a row
    // old enough for dueForFlush()/flushQueuedNotifications() to actually
    // pick it up must backdate it afterward -- this is exactly what
    // "having sat in the queue a while" looks like in a fast-running test.
    private function backdateQueuedNotification(int $userId, int $secondsAgo): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE queued_notifications SET queued_at = DATE_SUB(NOW(), INTERVAL :seconds_ago SECOND) WHERE user_id = :user_id'
        );
        $stmt->execute(['seconds_ago' => $secondsAgo, 'user_id' => $userId]);
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

        $service = new PushNotificationService($this->subscriptions, $this->preferences, $this->queuedNotifications);
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

        $service = new PushNotificationService($this->subscriptions, $this->preferences, $this->queuedNotifications);
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

        $service = new PushNotificationService($this->subscriptions, $this->preferences, $this->queuedNotifications);
        $service->notifyGameFinished($userId, 1, 'Game #1 is over -- you won!');

        self::addToAssertionCount(1);
    }

    // -- Five-minute per-user notification cooldown -------------------------

    public function testWasNotifiedRecentlyIsFalseUntilMarkNotified(): void
    {
        $userId = $this->insertUser('cooldown-user');

        self::assertFalse($this->preferences->wasNotifiedRecently($userId, 300));

        $this->preferences->markNotified($userId);

        self::assertTrue($this->preferences->wasNotifiedRecently($userId, 300));
    }

    public function testWasNotifiedRecentlyStopsMatchingOnceTheWindowPasses(): void
    {
        $userId = $this->insertUser('cooldown-expired');
        $this->preferences->markNotified($userId);

        // A 0-second window can never still be "within" it -- the
        // equivalent of the 5-minute cooldown already having elapsed.
        self::assertFalse($this->preferences->wasNotifiedRecently($userId, 0));
    }

    public function testMarkNotifiedDoesNotClobberExistingPreferences(): void
    {
        $userId = $this->insertUser('cooldown-preserves-prefs');
        $this->preferences->save($userId, false, false, true);

        $this->preferences->markNotified($userId);

        self::assertSame(
            ['notify_your_turn' => false, 'notify_friend_request' => false, 'notify_game_finished' => true],
            $this->preferences->forUser($userId)
        );
    }

    // PushNotificationService::notify() checks the cooldown before ever
    // looking at subscriptions or building a WebPush client -- pre-marking
    // it here means a still-configured, still-subscribed user's second
    // notification returns immediately without ever attempting the (slow,
    // unreachable-in-this-test) network call a real send would make. The
    // tight time assertion is exactly what distinguishes "skipped" from
    // "attempted and failed" -- a real send attempt against an
    // unreachable/fake endpoint takes measurably longer than this.
    public function testNotifyServiceSkipsSendingWhenAlreadyNotifiedRecently(): void
    {
        $userId = $this->insertUser('cooldown-skips-send');
        $this->subscriptions->save($userId, 'https://push.example.com/cooldown', 'p', 'a');
        $this->preferences->markNotified($userId);
        putenv('VAPID_PUBLIC_KEY=some-public-key');
        putenv('VAPID_PRIVATE_KEY=some-private-key');

        $service = new PushNotificationService($this->subscriptions, $this->preferences, $this->queuedNotifications);

        $start = microtime(true);
        $service->notifyYourTurn($userId, 1, 'Game #1 is waiting on your move.');
        $elapsed = microtime(true) - $start;

        self::assertLessThan(1.0, $elapsed, 'a cooldown-suppressed notify() should return immediately, never attempting a real send');
    }

    // -- Cooldown queue: replace-on-arrival, cron flush, clear-on-action ----

    // A second notification arriving before the first was ever delivered
    // must not accumulate a backlog -- it replaces the still-queued one, so
    // the user's eventual cron-flushed notification is the last one that
    // was actually true when it flushes, not a stale intermediate.
    public function testEnqueueReplacesPreviousQueuedNotificationForSameUser(): void
    {
        $userId = $this->insertUser('queue-replace');

        $this->queuedNotifications->enqueue($userId, 'notify_your_turn', [
            'title' => "It's your turn", 'body' => 'first', 'url' => '/game/?id=1', 'tag' => 'game-1-turn',
        ]);
        $this->queuedNotifications->enqueue($userId, 'notify_your_turn', [
            'title' => "It's your turn", 'body' => 'second', 'url' => '/game/?id=1', 'tag' => 'game-1-turn',
        ]);

        $rows = array_values(array_filter($this->queuedNotifications->all(), static fn (array $row) => $row['user_id'] === $userId));

        self::assertCount(1, $rows);
        self::assertSame('second', $rows[0]['body']);
    }

    public function testDueForFlushOnlyReturnsRowsAtLeastThatManySecondsOld(): void
    {
        $freshUserId = $this->insertUser('due-for-flush-fresh');
        $this->queuedNotifications->enqueue($freshUserId, 'notify_your_turn', [
            'title' => "It's your turn", 'body' => 'fresh', 'url' => '/game/?id=1', 'tag' => 'game-1-turn',
        ]);

        $staleUserId = $this->insertUser('due-for-flush-stale');
        $this->queuedNotifications->enqueue($staleUserId, 'notify_your_turn', [
            'title' => "It's your turn", 'body' => 'stale', 'url' => '/game/?id=2', 'tag' => 'game-2-turn',
        ]);
        $this->backdateQueuedNotification($staleUserId, 600);

        $due = array_column($this->queuedNotifications->dueForFlush(300), 'user_id');

        self::assertContains($staleUserId, $due);
        self::assertNotContains($freshUserId, $due);
    }

    // notify() checks the cooldown before ever looking at subscriptions or
    // VAPID configuration, so queueing during the cooldown needs neither --
    // this mirrors testNotifyServiceSkipsSendingWhenAlreadyNotifiedRecently()
    // but asserts what now happens instead of a silent drop.
    public function testNotifyQueuesInsteadOfDroppingDuringCooldownAndReplacesOnASecondArrival(): void
    {
        $userId = $this->insertUser('queue-on-cooldown');
        $this->preferences->markNotified($userId);

        $service = new PushNotificationService($this->subscriptions, $this->preferences, $this->queuedNotifications);
        $service->notifyYourTurn($userId, 7, 'Game #7 is waiting on your move.', 'turn');
        $service->notifyYourTurn($userId, 7, 'Game #7 is waiting on your move (again).', 'turn');

        $rows = array_values(array_filter($this->queuedNotifications->all(), static fn (array $row) => $row['user_id'] === $userId));

        self::assertCount(1, $rows);
        self::assertSame('Game #7 is waiting on your move (again).', $rows[0]['body']);
        self::assertSame('game-7-turn', $rows[0]['tag']);
    }

    public function testFlushQueuedNotificationsIsANoOpWhenNothingIsQueued(): void
    {
        $service = new PushNotificationService($this->subscriptions, $this->preferences, $this->queuedNotifications);

        self::assertSame(0, $service->flushQueuedNotifications());
    }

    // A notification that's only just landed in the queue must NOT be
    // flushed yet -- flushQueuedNotifications() only delivers rows queued
    // at least COOLDOWN_SECONDS ago (see QueuedNotificationRepository::
    // dueForFlush()'s own docblock), so a cron run landing moments after
    // something was queued gives the player a fair chance to clear it
    // themselves first, rather than delivering it immediately.
    public function testFlushQueuedNotificationsLeavesAFreshlyQueuedRowAlone(): void
    {
        $userId = $this->insertUser('flush-too-fresh');
        $this->subscriptions->save($userId, 'https://push.example.com/flush-too-fresh', 'p', 'a');
        $this->queuedNotifications->enqueue($userId, 'notify_your_turn', [
            'title' => "It's your turn", 'body' => 'b', 'url' => '/game/?id=1', 'tag' => 'game-1-turn',
        ]);
        putenv('VAPID_PUBLIC_KEY=some-public-key');
        putenv('VAPID_PRIVATE_KEY=some-private-key');

        $service = new PushNotificationService($this->subscriptions, $this->preferences, $this->queuedNotifications);

        self::assertSame(0, $service->flushQueuedNotifications());

        $rows = array_values(array_filter($this->queuedNotifications->all(), static fn (array $row) => $row['user_id'] === $userId));
        self::assertCount(1, $rows, 'a freshly-queued row must still be queued, not delivered or dropped');
    }

    // The queue is cleared as flushQueuedNotifications() walks it regardless
    // of whether the preference is still on -- re-checking at flush time
    // (not just at the original queue time) matters because the user may
    // have turned the preference off in the interim.
    public function testFlushQueuedNotificationsClearsTheQueueEvenWhenThePreferenceWasDisabledSinceQueueing(): void
    {
        $userId = $this->insertUser('flush-pref-disabled');
        $this->subscriptions->save($userId, 'https://push.example.com/flush-disabled', 'p', 'a');
        $this->queuedNotifications->enqueue($userId, 'notify_your_turn', [
            'title' => "It's your turn", 'body' => 'b', 'url' => '/game/?id=1', 'tag' => 'game-1-turn',
        ]);
        $this->backdateQueuedNotification($userId, 600);
        $this->preferences->save($userId, false, true, true);

        $service = new PushNotificationService($this->subscriptions, $this->preferences, $this->queuedNotifications);

        self::assertSame(0, $service->flushQueuedNotifications());
        self::assertSame([], array_filter($this->queuedNotifications->all(), static fn (array $row) => $row['user_id'] === $userId));
    }

    public function testFlushQueuedNotificationsClearsTheQueueEvenWithNoSubscriptions(): void
    {
        $userId = $this->insertUser('flush-no-subs');
        $this->queuedNotifications->enqueue($userId, 'notify_your_turn', [
            'title' => "It's your turn", 'body' => 'b', 'url' => '/game/?id=1', 'tag' => 'game-1-turn',
        ]);
        $this->backdateQueuedNotification($userId, 600);

        $service = new PushNotificationService($this->subscriptions, $this->preferences, $this->queuedNotifications);

        self::assertSame(0, $service->flushQueuedNotifications());
        self::assertSame([], array_filter($this->queuedNotifications->all(), static fn (array $row) => $row['user_id'] === $userId));
    }

    public function testFlushQueuedNotificationsClearsTheQueueWhenVapidIsNotConfigured(): void
    {
        $userId = $this->insertUser('flush-no-vapid');
        $this->subscriptions->save($userId, 'https://push.example.com/flush-no-vapid', 'p', 'a');
        $this->queuedNotifications->enqueue($userId, 'notify_your_turn', [
            'title' => "It's your turn", 'body' => 'b', 'url' => '/game/?id=1', 'tag' => 'game-1-turn',
        ]);
        $this->backdateQueuedNotification($userId, 600);
        putenv('VAPID_PUBLIC_KEY=');
        putenv('VAPID_PRIVATE_KEY=');

        $service = new PushNotificationService($this->subscriptions, $this->preferences, $this->queuedNotifications);

        self::assertSame(0, $service->flushQueuedNotifications());
        self::assertSame([], array_filter($this->queuedNotifications->all(), static fn (array $row) => $row['user_id'] === $userId));
    }

    // GameService::clearQueuedNotificationForGamePlayer()'s own passthrough
    // -- a queued reminder about a DIFFERENT game must survive untouched.
    public function testClearQueuedForGameOnlyClearsTheMatchingGame(): void
    {
        $userId = $this->insertUser('clear-for-game');
        $this->queuedNotifications->enqueue($userId, 'notify_your_turn', [
            'title' => "It's your turn", 'body' => 'game 4', 'url' => '/game/?id=4', 'tag' => 'game-4-turn',
        ]);

        $service = new PushNotificationService($this->subscriptions, $this->preferences, $this->queuedNotifications);

        // Game 42's own clear must not cross-match game 4's queued row --
        // the whole point of anchoring the LIKE pattern right after the id.
        $service->clearQueuedForGame($userId, 42);
        self::assertCount(1, array_filter($this->queuedNotifications->all(), static fn (array $row) => $row['user_id'] === $userId));

        $service->clearQueuedForGame($userId, 4);
        self::assertSame([], array_filter($this->queuedNotifications->all(), static fn (array $row) => $row['user_id'] === $userId));
    }

    public function testClearQueuedFriendRequestOnlyClearsTheFriendRequestTag(): void
    {
        $userId = $this->insertUser('clear-friend-request');
        $this->queuedNotifications->enqueue($userId, 'notify_your_turn', [
            'title' => "It's your turn", 'body' => 'still queued', 'url' => '/game/?id=1', 'tag' => 'game-1-turn',
        ]);

        $service = new PushNotificationService($this->subscriptions, $this->preferences, $this->queuedNotifications);
        $service->clearQueuedFriendRequest($userId);

        $rows = array_values(array_filter($this->queuedNotifications->all(), static fn (array $row) => $row['user_id'] === $userId));
        self::assertCount(1, $rows);
        self::assertSame('still queued', $rows[0]['body']);

        $this->queuedNotifications->enqueue($userId, 'notify_friend_request', [
            'title' => 'Friend request', 'body' => 'wants to be your friend', 'url' => '/friends/', 'tag' => 'friend-request',
        ]);
        $service->clearQueuedFriendRequest($userId);

        // The game-1 row was already replaced by the friend-request
        // enqueue() above (one row per user), so nothing is left at all.
        self::assertSame([], array_filter($this->queuedNotifications->all(), static fn (array $row) => $row['user_id'] === $userId));
    }
}
