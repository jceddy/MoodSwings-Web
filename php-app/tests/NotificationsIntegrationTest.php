<?php

declare(strict_types=1);

namespace MoodSwings\Tests;

use MoodSwings\Notifications\NotificationScope;
use MoodSwings\Notifications\NotificationService;
use MoodSwings\Notifications\PushNotificationChannel;
use MoodSwings\Repository\NotificationCooldownRepository;
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
        $pdo->exec('TRUNCATE TABLE push_subscriptions');
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
        $this->subscriptions = new PushSubscriptionRepository();
        $this->preferences = new NotificationPreferenceRepository();
        $this->queuedNotifications = new QueuedNotificationRepository();
        $this->cooldowns = new NotificationCooldownRepository();
    }

    protected function tearDown(): void
    {
        // Several tests below putenv() VAPID keys to exercise
        // NotificationService's "not configured" branch -- clear them
        // afterward so no other test file (running later in the same
        // PHPUnit process) inherits a stale value.
        putenv('VAPID_PUBLIC_KEY');
        putenv('VAPID_PRIVATE_KEY');
    }

    // Only the push channel is wired in here -- these tests cover the
    // shared preference/cooldown/queue orchestration NotificationService
    // itself owns, which behaves identically regardless of how many
    // channels are configured; Discord\DiscordNotificationChannel has its
    // own dedicated test coverage (DiscordNotificationChannelTest).
    private function service(): NotificationService
    {
        return new NotificationService($this->preferences, $this->queuedNotifications, $this->cooldowns, [
            new PushNotificationChannel($this->subscriptions),
        ]);
    }

    // enqueue() always stamps queued_at = NOW(), so tests that need a row
    // old enough for dueForFlush()/flushQueuedNotifications() to actually
    // pick it up must backdate it afterward -- this is exactly what
    // "having sat in the queue a while" looks like in a fast-running test.
    private function backdateQueuedNotification(int $userId, string $scope, int $secondsAgo): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE queued_notifications SET queued_at = DATE_SUB(NOW(), INTERVAL :seconds_ago SECOND) WHERE user_id = :user_id AND scope = :scope'
        );
        $stmt->execute(['seconds_ago' => $secondsAgo, 'user_id' => $userId, 'scope' => $scope]);
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
            ['notify_your_turn' => true, 'notify_friend_request' => true, 'notify_game_finished' => true, 'notify_chat_message' => true, 'disable_cooldown' => false],
            $this->preferences->forUser($userId)
        );

        $this->preferences->save($userId, false, true, false);

        self::assertSame(
            ['notify_your_turn' => false, 'notify_friend_request' => true, 'notify_game_finished' => false, 'notify_chat_message' => true, 'disable_cooldown' => false],
            $this->preferences->forUser($userId)
        );
    }

    public function testNotificationPreferencesSaveIsUpsert(): void
    {
        $userId = $this->insertUser('prefs-upsert');

        $this->preferences->save($userId, false, false, false);
        $this->preferences->save($userId, true, true, true);

        self::assertSame(
            ['notify_your_turn' => true, 'notify_friend_request' => true, 'notify_game_finished' => true, 'notify_chat_message' => true, 'disable_cooldown' => false],
            $this->preferences->forUser($userId)
        );
    }

    // notify_chat_message (issue #109) defaults on like the three
    // notify_* toggles above, independently settable/upsertable from all
    // of them and from disable_cooldown -- mirrors
    // testDisableCooldownPreferenceDefaultsOffAndIsIndependentlySettable
    // below for the newer toggle.
    public function testNotifyChatMessagePreferenceDefaultsOnAndIsIndependentlySettable(): void
    {
        $userId = $this->insertUser('prefs-chat-message');

        self::assertTrue($this->preferences->forUser($userId)['notify_chat_message']);

        $this->preferences->save($userId, true, true, true, false, false);

        self::assertSame(
            ['notify_your_turn' => true, 'notify_friend_request' => true, 'notify_game_finished' => true, 'notify_chat_message' => false, 'disable_cooldown' => false],
            $this->preferences->forUser($userId)
        );
    }

    // disable_cooldown (migration 0051) defaults to false -- untouched,
    // it must default off just like every other never-saved preference
    // defaults on -- and is independently settable/upsertable from the
    // three notify_* toggles, the same way each of those already is from
    // one another.
    public function testDisableCooldownPreferenceDefaultsOffAndIsIndependentlySettable(): void
    {
        $userId = $this->insertUser('prefs-disable-cooldown');

        self::assertFalse($this->preferences->forUser($userId)['disable_cooldown']);

        $this->preferences->save($userId, true, true, true, true);

        self::assertSame(
            ['notify_your_turn' => true, 'notify_friend_request' => true, 'notify_game_finished' => true, 'notify_chat_message' => true, 'disable_cooldown' => true],
            $this->preferences->forUser($userId)
        );

        // Saving again without passing disable_cooldown falls back to its
        // own default (false) rather than preserving whatever was there
        // before -- save() always writes a complete row, matching how the
        // three notify_* toggles already behave (see
        // testNotificationPreferencesSaveIsUpsert() above).
        $this->preferences->save($userId, true, true, true);
        self::assertFalse($this->preferences->forUser($userId)['disable_cooldown']);
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

    // NotificationService's notify() must never attempt a network call
    // (and therefore never throw) when there's nothing to send to -- these
    // cases are all resolved from the database alone, before WebPush is
    // ever constructed, so they're safe to assert without a real push
    // service or configured VAPID keys.
    public function testNotifyIsANoOpWhenTheUserHasNoSubscriptions(): void
    {
        $userId = $this->insertUser('no-subscriptions');
        putenv('VAPID_PUBLIC_KEY=some-public-key');
        putenv('VAPID_PRIVATE_KEY=some-private-key');

        $this->service()->notifyYourTurn($userId, 1, 'Game #1 is waiting on your move.');

        self::addToAssertionCount(1); // reaching this line without throwing is the assertion
    }

    public function testNotifyIsANoOpWhenThePreferenceIsDisabled(): void
    {
        $userId = $this->insertUser('pref-disabled');
        $this->subscriptions->save($userId, 'https://push.example.com/disabled', 'p', 'a');
        $this->preferences->save($userId, false, true, true);
        putenv('VAPID_PUBLIC_KEY=some-public-key');
        putenv('VAPID_PRIVATE_KEY=some-private-key');

        // notify_your_turn is off -- even though a subscription exists,
        // this must return before ever touching the network.
        $this->service()->notifyYourTurn($userId, 1, 'Game #1 is waiting on your move.');

        self::addToAssertionCount(1);
    }

    public function testNotifyIsANoOpWhenVapidKeysAreNotConfigured(): void
    {
        $userId = $this->insertUser('no-vapid-keys');
        $this->subscriptions->save($userId, 'https://push.example.com/no-vapid', 'p', 'a');
        putenv('VAPID_PUBLIC_KEY=');
        putenv('VAPID_PRIVATE_KEY=');

        $this->service()->notifyGameFinished($userId, 1, 'Game #1 is over -- you won!');

        self::addToAssertionCount(1);
    }

    public function testNotifyNewChatMessageIsANoOpWhenTheUserHasNoSubscriptions(): void
    {
        $userId = $this->insertUser('chat-no-subscriptions');
        putenv('VAPID_PUBLIC_KEY=some-public-key');
        putenv('VAPID_PRIVATE_KEY=some-private-key');

        $this->service()->notifyNewChatMessage($userId, 1, 'alice', 'hey, your move');

        self::addToAssertionCount(1);
    }

    // In-game chat (issue #109) shares NotificationScope::forGame() with
    // notifyYourTurn()/notifyGameFinished() rather than its own scope --
    // see NotificationService::notifyNewChatMessage()'s own docblock --
    // so a chat message arriving right after this game's scope was
    // already marked notified must queue rather than send immediately,
    // exactly like notifyYourTurn() already does in
    // testNotifyServiceSkipsSendingWhenAlreadyNotifiedRecentlyAboutTheSameGame()
    // below.
    public function testNotifyNewChatMessageSharesCooldownScopeWithOtherGameNotifications(): void
    {
        $userId = $this->insertUser('chat-shares-cooldown');
        $this->subscriptions->save($userId, 'https://push.example.com/chat-cooldown', 'p', 'a');
        $this->cooldowns->markNotified($userId, NotificationScope::forGame(1));
        putenv('VAPID_PUBLIC_KEY=some-public-key');
        putenv('VAPID_PRIVATE_KEY=some-private-key');

        $this->service()->notifyNewChatMessage($userId, 1, 'alice', 'hey, your move');

        $queued = array_filter($this->queuedNotifications->all(), static fn (array $row) => $row['user_id'] === $userId);
        self::assertCount(1, $queued);
        self::assertSame('notify_chat_message', array_values($queued)[0]['preference_key']);
    }

    // -- Five-minute cooldown, scoped per (user, game) -----------------------

    public function testWasNotifiedRecentlyIsFalseUntilMarkNotified(): void
    {
        $userId = $this->insertUser('cooldown-user');
        $scope = NotificationScope::forGame(1);

        self::assertFalse($this->cooldowns->wasNotifiedRecently($userId, $scope, 300));

        $this->cooldowns->markNotified($userId, $scope);

        self::assertTrue($this->cooldowns->wasNotifiedRecently($userId, $scope, 300));
    }

    public function testWasNotifiedRecentlyStopsMatchingOnceTheWindowPasses(): void
    {
        $userId = $this->insertUser('cooldown-expired');
        $scope = NotificationScope::forGame(1);
        $this->cooldowns->markNotified($userId, $scope);

        // A 0-second window can never still be "within" it -- the
        // equivalent of the 5-minute cooldown already having elapsed.
        self::assertFalse($this->cooldowns->wasNotifiedRecently($userId, $scope, 0));
    }

    // The whole point of scoping the cooldown per (user, game) rather than
    // globally per user: being notified about one game must never start
    // (or extend) another game's own cooldown.
    public function testMarkNotifiedForOneGameDoesNotAffectAnotherGamesCooldown(): void
    {
        $userId = $this->insertUser('cooldown-per-game');
        $this->cooldowns->markNotified($userId, NotificationScope::forGame(1));

        self::assertTrue($this->cooldowns->wasNotifiedRecently($userId, NotificationScope::forGame(1), 300));
        self::assertFalse($this->cooldowns->wasNotifiedRecently($userId, NotificationScope::forGame(2), 300));
    }

    // NotificationService::notify() checks the cooldown before ever
    // looking at subscriptions or building a WebPush client -- pre-marking
    // it here means a still-configured, still-subscribed user's second
    // notification for the SAME game returns immediately without ever
    // attempting the (slow, unreachable-in-this-test) network call a real
    // send would make. The tight time assertion is exactly what
    // distinguishes "skipped" from "attempted and failed" -- a real send
    // attempt against an unreachable/fake endpoint takes measurably longer
    // than this.
    public function testNotifyServiceSkipsSendingWhenAlreadyNotifiedRecentlyAboutTheSameGame(): void
    {
        $userId = $this->insertUser('cooldown-skips-send');
        $this->subscriptions->save($userId, 'https://push.example.com/cooldown', 'p', 'a');
        $this->cooldowns->markNotified($userId, NotificationScope::forGame(1));
        putenv('VAPID_PUBLIC_KEY=some-public-key');
        putenv('VAPID_PRIVATE_KEY=some-private-key');

        $start = microtime(true);
        $this->service()->notifyYourTurn($userId, 1, 'Game #1 is waiting on your move.');
        $elapsed = microtime(true) - $start;

        self::assertLessThan(1.0, $elapsed, 'a cooldown-suppressed notify() should return immediately, never attempting a real send');
    }

    // The other half of scoping by game: a user already in game 1's
    // cooldown must still be sent a live notification about a DIFFERENT
    // game -- playing several games at once can still mean more than one
    // push within 5 minutes overall, just never more than one about any
    // one specific game. No subscriptions exist here, so this stays a
    // fast, network-free no-op assertion (see the "no subscriptions"
    // group above) while still proving the *cooldown itself* didn't block
    // it -- if it had, this would behave identically either way, so what
    // actually distinguishes this test is timing: it returns just as fast
    // as the "no subscriptions" case, never hitting the queue path a
    // blocked cooldown would take.
    public function testNotifyStillSendsForADifferentGameDespiteAnotherGamesCooldown(): void
    {
        $userId = $this->insertUser('cooldown-different-game');
        $this->cooldowns->markNotified($userId, NotificationScope::forGame(1));

        $this->service()->notifyYourTurn($userId, 2, 'Game #2 is waiting on your move.');

        // Not queued -- notify() only queues when THIS game's own scope is
        // within cooldown, which game 2's never was.
        self::assertSame([], array_filter($this->queuedNotifications->all(), static fn (array $row) => $row['user_id'] === $userId));
    }

    // disable_cooldown (migration 0051) skips notify()'s own
    // wasNotifiedRecently() check outright -- unlike the "different game"
    // case above, THIS game's own scope really was just marked notified,
    // so without the preference on this would take the queue branch. No
    // subscriptions exist, so there's nothing to actually attempt to
    // deliver to either way -- what's under test is purely that the queue
    // branch itself was never reached despite the cooldown being live.
    public function testDisableCooldownPreferenceSkipsQueueingEvenWithinCooldown(): void
    {
        $userId = $this->insertUser('cooldown-disabled');
        $this->preferences->save($userId, true, true, true, true);
        $this->cooldowns->markNotified($userId, NotificationScope::forGame(1));

        $this->service()->notifyYourTurn($userId, 1, 'Game #1 is waiting on your move.');

        self::assertSame([], array_filter($this->queuedNotifications->all(), static fn (array $row) => $row['user_id'] === $userId));
    }

    // -- Cooldown queue: replace-on-arrival PER SCOPE, cron flush, clear-on-action

    // A second notification arriving before the first was ever delivered
    // must not accumulate a backlog -- it replaces the still-queued one
    // for that SAME scope, so the user's eventual cron-flushed notification
    // is the last one that was actually true when it flushes, not a stale
    // intermediate.
    public function testEnqueueReplacesPreviousQueuedNotificationForTheSameScope(): void
    {
        $userId = $this->insertUser('queue-replace');
        $scope = NotificationScope::forGame(1);

        $this->queuedNotifications->enqueue($userId, $scope, 'notify_your_turn', [
            'title' => "It's your turn", 'body' => 'first', 'url' => '/game/?id=1', 'tag' => 'game-1-turn',
        ]);
        $this->queuedNotifications->enqueue($userId, $scope, 'notify_your_turn', [
            'title' => "It's your turn", 'body' => 'second', 'url' => '/game/?id=1', 'tag' => 'game-1-turn',
        ]);

        $rows = array_values(array_filter($this->queuedNotifications->all(), static fn (array $row) => $row['user_id'] === $userId));

        self::assertCount(1, $rows);
        self::assertSame('second', $rows[0]['body']);
    }

    // The central behavior this whole per-scope redesign is for: queueing
    // a notification for one game must never bump (replace, or be
    // replaced by) one already queued for a DIFFERENT game -- a player in
    // several games at once can end up with several simultaneously
    // queued rows, one per game.
    public function testEnqueueForOneGameDoesNotBumpAQueuedNotificationForAnotherGame(): void
    {
        $userId = $this->insertUser('queue-per-game');

        $this->queuedNotifications->enqueue($userId, NotificationScope::forGame(1), 'notify_your_turn', [
            'title' => "It's your turn", 'body' => 'game 1', 'url' => '/game/?id=1', 'tag' => 'game-1-turn',
        ]);
        $this->queuedNotifications->enqueue($userId, NotificationScope::forGame(2), 'notify_your_turn', [
            'title' => "It's your turn", 'body' => 'game 2', 'url' => '/game/?id=2', 'tag' => 'game-2-turn',
        ]);

        $rows = array_values(array_filter($this->queuedNotifications->all(), static fn (array $row) => $row['user_id'] === $userId));
        usort($rows, static fn (array $a, array $b) => $a['body'] <=> $b['body']);

        self::assertCount(2, $rows, 'a game 2 notification must not replace a still-queued game 1 one');
        self::assertSame('game 1', $rows[0]['body']);
        self::assertSame('game 2', $rows[1]['body']);
    }

    public function testDueForFlushOnlyReturnsRowsAtLeastThatManySecondsOld(): void
    {
        $freshUserId = $this->insertUser('due-for-flush-fresh');
        $this->queuedNotifications->enqueue($freshUserId, NotificationScope::forGame(1), 'notify_your_turn', [
            'title' => "It's your turn", 'body' => 'fresh', 'url' => '/game/?id=1', 'tag' => 'game-1-turn',
        ]);

        $staleUserId = $this->insertUser('due-for-flush-stale');
        $this->queuedNotifications->enqueue($staleUserId, NotificationScope::forGame(2), 'notify_your_turn', [
            'title' => "It's your turn", 'body' => 'stale', 'url' => '/game/?id=2', 'tag' => 'game-2-turn',
        ]);
        $this->backdateQueuedNotification($staleUserId, NotificationScope::forGame(2), 600);

        $due = array_column($this->queuedNotifications->dueForFlush(300), 'user_id');

        self::assertContains($staleUserId, $due);
        self::assertNotContains($freshUserId, $due);
    }

    // notify() checks the cooldown before ever looking at subscriptions or
    // VAPID configuration, so queueing during the cooldown needs neither --
    // this mirrors testNotifyServiceSkipsSendingWhenAlreadyNotifiedRecentlyAboutTheSameGame()
    // but asserts what now happens instead of a silent drop.
    public function testNotifyQueuesInsteadOfDroppingDuringCooldownAndReplacesOnASecondArrival(): void
    {
        $userId = $this->insertUser('queue-on-cooldown');
        $this->cooldowns->markNotified($userId, NotificationScope::forGame(7));

        $service = $this->service();
        $service->notifyYourTurn($userId, 7, 'Game #7 is waiting on your move.', 'turn');
        $service->notifyYourTurn($userId, 7, 'Game #7 is waiting on your move (again).', 'turn');

        $rows = array_values(array_filter($this->queuedNotifications->all(), static fn (array $row) => $row['user_id'] === $userId));

        self::assertCount(1, $rows);
        self::assertSame('Game #7 is waiting on your move (again).', $rows[0]['body']);
        self::assertSame('game-7-turn', $rows[0]['tag']);
    }

    public function testFlushQueuedNotificationsIsANoOpWhenNothingIsQueued(): void
    {
        self::assertSame(0, $this->service()->flushQueuedNotifications());
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
        $this->queuedNotifications->enqueue($userId, NotificationScope::forGame(1), 'notify_your_turn', [
            'title' => "It's your turn", 'body' => 'b', 'url' => '/game/?id=1', 'tag' => 'game-1-turn',
        ]);
        putenv('VAPID_PUBLIC_KEY=some-public-key');
        putenv('VAPID_PRIVATE_KEY=some-private-key');

        self::assertSame(0, $this->service()->flushQueuedNotifications());

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
        $this->queuedNotifications->enqueue($userId, NotificationScope::forGame(1), 'notify_your_turn', [
            'title' => "It's your turn", 'body' => 'b', 'url' => '/game/?id=1', 'tag' => 'game-1-turn',
        ]);
        $this->backdateQueuedNotification($userId, NotificationScope::forGame(1), 600);
        $this->preferences->save($userId, false, true, true);

        self::assertSame(0, $this->service()->flushQueuedNotifications());
        self::assertSame([], array_filter($this->queuedNotifications->all(), static fn (array $row) => $row['user_id'] === $userId));
    }

    public function testFlushQueuedNotificationsClearsTheQueueEvenWithNoSubscriptions(): void
    {
        $userId = $this->insertUser('flush-no-subs');
        $this->queuedNotifications->enqueue($userId, NotificationScope::forGame(1), 'notify_your_turn', [
            'title' => "It's your turn", 'body' => 'b', 'url' => '/game/?id=1', 'tag' => 'game-1-turn',
        ]);
        $this->backdateQueuedNotification($userId, NotificationScope::forGame(1), 600);

        self::assertSame(0, $this->service()->flushQueuedNotifications());
        self::assertSame([], array_filter($this->queuedNotifications->all(), static fn (array $row) => $row['user_id'] === $userId));
    }

    public function testFlushQueuedNotificationsClearsTheQueueWhenVapidIsNotConfigured(): void
    {
        $userId = $this->insertUser('flush-no-vapid');
        $this->subscriptions->save($userId, 'https://push.example.com/flush-no-vapid', 'p', 'a');
        $this->queuedNotifications->enqueue($userId, NotificationScope::forGame(1), 'notify_your_turn', [
            'title' => "It's your turn", 'body' => 'b', 'url' => '/game/?id=1', 'tag' => 'game-1-turn',
        ]);
        $this->backdateQueuedNotification($userId, NotificationScope::forGame(1), 600);
        putenv('VAPID_PUBLIC_KEY=');
        putenv('VAPID_PRIVATE_KEY=');

        self::assertSame(0, $this->service()->flushQueuedNotifications());
        self::assertSame([], array_filter($this->queuedNotifications->all(), static fn (array $row) => $row['user_id'] === $userId));
    }

    // Flushing one user's due game-1 row must leave another user's (or the
    // same user's) still-fresh game-2 row alone -- dueForFlush() is scoped
    // per row, not per user.
    public function testFlushQueuedNotificationsOnlyClearsTheDueRowNotOtherScopesForTheSameUser(): void
    {
        $userId = $this->insertUser('flush-per-scope');
        $this->queuedNotifications->enqueue($userId, NotificationScope::forGame(1), 'notify_your_turn', [
            'title' => "It's your turn", 'body' => 'due', 'url' => '/game/?id=1', 'tag' => 'game-1-turn',
        ]);
        $this->backdateQueuedNotification($userId, NotificationScope::forGame(1), 600);
        $this->queuedNotifications->enqueue($userId, NotificationScope::forGame(2), 'notify_your_turn', [
            'title' => "It's your turn", 'body' => 'still fresh', 'url' => '/game/?id=2', 'tag' => 'game-2-turn',
        ]);

        $this->service()->flushQueuedNotifications();

        $rows = array_values(array_filter($this->queuedNotifications->all(), static fn (array $row) => $row['user_id'] === $userId));
        self::assertCount(1, $rows);
        self::assertSame('still fresh', $rows[0]['body']);
    }

    // GameService::clearQueuedNotificationForGamePlayer()'s own passthrough
    // -- a queued reminder about a DIFFERENT game must survive untouched.
    public function testClearQueuedForGameOnlyClearsTheMatchingGame(): void
    {
        $userId = $this->insertUser('clear-for-game');
        $this->queuedNotifications->enqueue($userId, NotificationScope::forGame(4), 'notify_your_turn', [
            'title' => "It's your turn", 'body' => 'game 4', 'url' => '/game/?id=4', 'tag' => 'game-4-turn',
        ]);

        $service = $this->service();

        // Game 42's own clear must not cross-match game 4's queued row.
        $service->clearQueuedForGame($userId, 42);
        self::assertCount(1, array_filter($this->queuedNotifications->all(), static fn (array $row) => $row['user_id'] === $userId));

        $service->clearQueuedForGame($userId, 4);
        self::assertSame([], array_filter($this->queuedNotifications->all(), static fn (array $row) => $row['user_id'] === $userId));
    }

    public function testClearQueuedFriendRequestOnlyClearsTheFriendRequestScope(): void
    {
        $userId = $this->insertUser('clear-friend-request');
        $this->queuedNotifications->enqueue($userId, NotificationScope::forGame(1), 'notify_your_turn', [
            'title' => "It's your turn", 'body' => 'still queued', 'url' => '/game/?id=1', 'tag' => 'game-1-turn',
        ]);
        $this->queuedNotifications->enqueue($userId, NotificationScope::FRIEND_REQUEST, 'notify_friend_request', [
            'title' => 'Friend request', 'body' => 'wants to be your friend', 'url' => '/friends/', 'tag' => 'friend-request',
        ]);

        // Different scopes, so both rows coexist rather than one
        // replacing the other.
        self::assertCount(2, array_filter($this->queuedNotifications->all(), static fn (array $row) => $row['user_id'] === $userId));

        $this->service()->clearQueuedFriendRequest($userId);

        $rows = array_values(array_filter($this->queuedNotifications->all(), static fn (array $row) => $row['user_id'] === $userId));
        self::assertCount(1, $rows);
        self::assertSame('still queued', $rows[0]['body']);
    }
}
