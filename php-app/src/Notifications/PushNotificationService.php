<?php

declare(strict_types=1);

namespace MoodSwings\Notifications;

use Minishlink\WebPush\ContentEncoding;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use MoodSwings\Config;
use MoodSwings\Repository\NotificationCooldownRepository;
use MoodSwings\Repository\NotificationPreferenceRepository;
use MoodSwings\Repository\PushSubscriptionRepository;
use MoodSwings\Repository\QueuedNotificationRepository;

/**
 * Sends browser push notifications for issue #108's first pass (Push API +
 * Notifications API via a Service Worker). Discord notifications are a
 * separate, later effort -- this class only ever talks to web push
 * services, never Discord's API.
 *
 * Every public notifyXxx() method is a fire-and-forget best effort: a push
 * service being unreachable, a stale/expired subscription, or VAPID keys
 * not being configured (e.g. a local dev environment) must never fail the
 * request that triggered it (sending an invite, finishing a game, taking a
 * turn) -- so every failure is swallowed here rather than thrown.
 *
 * notify() also enforces a 5-minute cooldown (COOLDOWN_SECONDS, see
 * NotificationCooldownRepository), scoped per (user, NotificationScope)
 * rather than globally per user -- a player active in several games at
 * once can still get more than one push within 5 minutes overall, but
 * never more than one within 5 minutes about any one specific game (see
 * NotificationScope's own docblock). Rather than simply dropping a
 * notification that arrives during its scope's cooldown, it's queued
 * instead (QueuedNotificationRepository, one row per (user, scope),
 * replacing whatever was queued for that same scope before) and delivered
 * later by flushQueuedNotifications() -- see
 * bin/send_queued_notifications.php, meant to run every ~15 minutes via
 * cron. GameService::clearQueuedNotificationForGamePlayer()/
 * clearQueuedForGame()/clearQueuedFriendRequest() delete a queued row
 * early if the player takes the action it would have reminded them about
 * before the flush ever runs.
 */
final class PushNotificationService
{
    private const COOLDOWN_SECONDS = 300;

    public function __construct(
        private readonly PushSubscriptionRepository $subscriptions,
        private readonly NotificationPreferenceRepository $preferences,
        private readonly QueuedNotificationRepository $queuedNotifications,
        private readonly NotificationCooldownRepository $cooldowns,
    ) {
    }

    /**
     * $tag distinguishes GameService's several "waiting on you" cases for
     * the same game (an ordinary turn advance, a Compulsion-style pending
     * decision, a team turn_order/draw_recipient decision, closed_team's
     * pregame card pass, a draft match's first-player-choice freeze) so
     * the OS never collapses two different ones into a single
     * notification -- see GameService::notifyGamePlayersItsYourTurn().
     * The cooldown/queue scope is coarser than $tag though -- every one of
     * these, plus notifyGameFinished() below, shares one scope per game
     * (NotificationScope::forGame()), not one per tag.
     */
    public function notifyYourTurn(int $userId, int $gameId, string $body, string $tag = 'turn'): void
    {
        $this->notify($userId, NotificationScope::forGame($gameId), 'notify_your_turn', [
            'title' => "It's your turn",
            'body' => $body,
            'url' => "/game/?id={$gameId}",
            'tag' => "game-{$gameId}-{$tag}",
        ]);
    }

    public function notifyFriendRequest(int $userId, string $requesterUsername): void
    {
        $this->notify($userId, NotificationScope::FRIEND_REQUEST, 'notify_friend_request', [
            'title' => 'Friend request',
            'body' => "{$requesterUsername} wants to be your friend.",
            'url' => '/friends/',
            'tag' => 'friend-request',
        ]);
    }

    public function notifyGameFinished(int $userId, int $gameId, string $resultSummary): void
    {
        $this->notify($userId, NotificationScope::forGame($gameId), 'notify_game_finished', [
            'title' => 'Game finished',
            'body' => $resultSummary,
            'url' => "/game/?id={$gameId}",
            'tag' => "game-{$gameId}-finished",
        ]);
    }

    /** GameService::clearQueuedNotificationForGamePlayer()'s own passthrough -- see that method's docblock. */
    public function clearQueuedForGame(int $userId, int $gameId): void
    {
        $this->queuedNotifications->clearForGameIfMatches($userId, $gameId);
    }

    /** The `/friends/respond` route's own passthrough, mirroring clearQueuedForGame() for the one non-game scope. */
    public function clearQueuedFriendRequest(int $userId): void
    {
        $this->queuedNotifications->clearFriendRequestForUser($userId);
    }

    /**
     * bin/send_queued_notifications.php's own cron flush -- delivers
     * whatever's queued and old enough to flush, clearing each row as it
     * goes. "Old enough" means queued at least COOLDOWN_SECONDS ago
     * (see QueuedNotificationRepository::dueForFlush()'s own docblock) --
     * a row queued more recently is left alone for a later flush, so a
     * cron run landing moments after something was queued doesn't
     * deliver it before the player's had a fair chance to clear it
     * themselves by taking the action it was reminding them about.
     *
     * Otherwise bypasses the cooldown check entirely (that's the whole
     * point: this IS the delayed delivery), but still re-checks the
     * relevant preference (the user may have turned it off between
     * queueing and now) and still respects VAPID/subscription
     * availability the same way an ordinary send does. Still stamps the
     * scope's cooldown forward on an actual send, so a live event for
     * that same scope immediately afterward doesn't turn right around and
     * re-queue on top of what was just delivered.
     *
     * @return int how many notifications were actually sent
     */
    public function flushQueuedNotifications(): int
    {
        $sent = 0;

        foreach ($this->queuedNotifications->dueForFlush(self::COOLDOWN_SECONDS) as $row) {
            $userId = $row['user_id'];
            $scope = $row['scope'];

            // One row throwing (e.g. a stale WebPush call, a DB hiccup)
            // must not abort the flush for every other queued row behind
            // it in this same cron run.
            try {
                if ($this->preferences->forUser($userId)[$row['preference_key']]) {
                    $subscriptions = $this->subscriptions->listForUser($userId);
                    $webPush = $subscriptions !== [] ? $this->webPush() : null;

                    if ($webPush !== null) {
                        $this->sendNow($webPush, $subscriptions, [
                            'title' => $row['title'],
                            'body' => $row['body'],
                            'url' => $row['url'],
                            'tag' => $row['tag'],
                        ]);
                        $this->cooldowns->markNotified($userId, $scope);
                        $sent++;
                    }
                }

                $this->queuedNotifications->delete($userId, $scope);
            } catch (\Throwable $e) {
                $this->logError("Failed to flush queued notification (user {$userId}, scope {$scope}): " . $e->getMessage());
            }
        }

        return $sent;
    }

    /**
     * @param array{title: string, body: string, url: string, tag: string} $payload
     */
    private function notify(int $userId, string $scope, string $preferenceKey, array $payload): void
    {
        // The class docblock above promises every failure here is
        // swallowed, not thrown -- but nothing below actually enforced
        // that (a missing/out-of-date notification_* table, or a WebPush
        // library exception, would otherwise propagate straight out of
        // notifyFriendRequest()/notifyYourTurn()/notifyGameFinished() and
        // fail the request that triggered it, e.g. sending a friend
        // invite). Catching here is what actually makes the guarantee
        // true.
        try {
            if (!$this->preferences->forUser($userId)[$preferenceKey]) {
                $this->logError("Not sending notification (user {$userId}, scope {$scope}): preference '{$preferenceKey}' is off");
                return;
            }

            if ($this->cooldowns->wasNotifiedRecently($userId, $scope, self::COOLDOWN_SECONDS)) {
                $this->queuedNotifications->enqueue($userId, $scope, $preferenceKey, $payload);
                $this->logError("Queued notification instead of sending (user {$userId}, scope {$scope}): within the cooldown window");
                return;
            }

            $subscriptions = $this->subscriptions->listForUser($userId);
            if ($subscriptions === []) {
                $this->logError("Not sending notification (user {$userId}, scope {$scope}): no push subscriptions on file");
                return;
            }

            $webPush = $this->webPush();
            if ($webPush === null) {
                $this->logError("Not sending notification (user {$userId}, scope {$scope}): VAPID keys are not configured");
                return;
            }

            // Only stamp the cooldown -- and clear anything queued for this
            // scope -- once sendNow() actually completes without throwing.
            // Marking it beforehand (as this used to) meant a send that
            // failed partway (e.g. the WebPush library throwing before any
            // subscription was actually reached) still burned the 5-minute
            // cooldown despite nothing being delivered, silently demoting
            // the *next* attempt to a queued one instead of a live send --
            // exactly what made the friend-request-notification fix above
            // look like it hadn't taken effect, once a single failed
            // attempt (e.g. the missing-PSR-18-client bug) had already
            // stamped the cooldown.
            $this->sendNow($webPush, $subscriptions, $payload);

            $this->cooldowns->markNotified($userId, $scope);
            $this->queuedNotifications->delete($userId, $scope);
        } catch (\Throwable $e) {
            $this->logError("Failed to send notification (user {$userId}, scope {$scope}): " . $e->getMessage());
        }
    }

    /**
     * @param array<int, array{id: int, endpoint: string, p256dh_key: string, auth_key: string}> $subscriptions
     * @param array{title: string, body: string, url: string, tag: string} $payload
     */
    private function sendNow(WebPush $webPush, array $subscriptions, array $payload): void
    {
        $encodedPayload = json_encode($payload);

        foreach ($subscriptions as $subscription) {
            $webPush->queueNotification(
                new Subscription($subscription['endpoint'], $subscription['p256dh_key'], $subscription['auth_key'], ContentEncoding::aes128gcm),
                $encodedPayload
            );
        }

        foreach ($webPush->flush() as $report) {
            if ($report->isSuccess()) {
                continue;
            }

            if ($report->isSubscriptionExpired()) {
                $this->pruneExpired($subscriptions, $report->getEndpoint());
                continue;
            }

            // Anything else (a VAPID key mismatch, an auth error, a
            // malformed payload, the push service itself erroring) was
            // previously dropped here with no trace at all -- the report
            // isn't a PHP exception (WebPush::flush() never throws per
            // message), so nothing upstream ever saw it, and the send
            // looked identical to a quiet success. Logging every
            // non-expired failure is what actually makes a bad send
            // diagnosable instead of just silently not happening.
            $status = $report->getResponse()?->getStatusCode();
            $this->logError(sprintf(
                'Push send failed for endpoint %s: %s (HTTP %s) %s',
                $report->getEndpoint(),
                $report->getReason(),
                $status ?? 'n/a',
                $report->getResponseContent() ?? '',
            ));
        }
    }

    /**
     * @param array<int, array{id: int, endpoint: string, p256dh_key: string, auth_key: string}> $subscriptions
     */
    private function pruneExpired(array $subscriptions, string $expiredEndpoint): void
    {
        foreach ($subscriptions as $subscription) {
            if ($subscription['endpoint'] === $expiredEndpoint) {
                $this->subscriptions->deleteById($subscription['id']);
                return;
            }
        }
    }

    /** Same convention as index.php's logMailError() -- a dedicated log file rather than the general PHP error log, so a noisy push failure (e.g. every subscription for a since-deactivated browser expiring at once) doesn't drown out unrelated errors. */
    private function logError(string $message): void
    {
        $line = '[' . date('Y-m-d H:i:s') . "] {$message}\n";
        error_log($line, 3, dirname(__DIR__) . '/notification-errors.log');
    }

    private function webPush(): ?WebPush
    {
        $publicKey = Config::get('VAPID_PUBLIC_KEY', '');
        $privateKey = Config::get('VAPID_PRIVATE_KEY', '');

        if ($publicKey === '' || $privateKey === '') {
            return null;
        }

        return new WebPush([
            'VAPID' => [
                'subject' => Config::get('VAPID_SUBJECT', 'mailto:support@example.com'),
                'publicKey' => $publicKey,
                'privateKey' => $privateKey,
            ],
        ]);
    }
}
