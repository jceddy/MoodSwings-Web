<?php

declare(strict_types=1);

namespace MoodSwings\Notifications;

use Minishlink\WebPush\ContentEncoding;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use MoodSwings\Config;
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
 * notify() also enforces a global per-user cooldown (COOLDOWN_SECONDS, see
 * NotificationPreferenceRepository::wasNotifiedRecently()/markNotified())
 * -- regardless of event type or which game it's about, so a player
 * actively working through several turns/decisions in a row isn't sent one
 * push per event. Rather than simply dropping a notification that arrives
 * during the cooldown, it's queued instead (QueuedNotificationRepository,
 * one row per user, replacing whatever was queued before) and delivered
 * later by flushQueuedNotifications() -- see bin/send_queued_notifications.php,
 * meant to run every ~15 minutes via cron. GameService::
 * clearQueuedNotificationForGamePlayer()/clearQueuedForGame()/
 * clearQueuedFriendRequest() delete a queued row early if the player takes
 * the action it would have reminded them about before the flush ever runs.
 */
final class PushNotificationService
{
    private const COOLDOWN_SECONDS = 300;

    public function __construct(
        private readonly PushSubscriptionRepository $subscriptions,
        private readonly NotificationPreferenceRepository $preferences,
        private readonly QueuedNotificationRepository $queuedNotifications,
    ) {
    }

    /**
     * $tag distinguishes GameService's several "waiting on you" cases for
     * the same game (an ordinary turn advance, a Compulsion-style pending
     * decision, a team turn_order/draw_recipient decision, closed_team's
     * pregame card pass, a draft match's first-player-choice freeze) so
     * the OS never collapses two different ones into a single
     * notification -- see GameService::notifyGamePlayersItsYourTurn().
     */
    public function notifyYourTurn(int $userId, int $gameId, string $body, string $tag = 'turn'): void
    {
        $this->notify($userId, 'notify_your_turn', [
            'title' => "It's your turn",
            'body' => $body,
            'url' => "/game/?id={$gameId}",
            'tag' => "game-{$gameId}-{$tag}",
        ]);
    }

    public function notifyFriendRequest(int $userId, string $requesterUsername): void
    {
        $this->notify($userId, 'notify_friend_request', [
            'title' => 'Friend request',
            'body' => "{$requesterUsername} wants to be your friend.",
            'url' => '/friends/',
            'tag' => 'friend-request',
        ]);
    }

    public function notifyGameFinished(int $userId, int $gameId, string $resultSummary): void
    {
        $this->notify($userId, 'notify_game_finished', [
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

    /** The `/friends/respond` route's own passthrough, mirroring clearQueuedForGame() for the one non-game-scoped tag. */
    public function clearQueuedFriendRequest(int $userId): void
    {
        $this->queuedNotifications->clearFriendRequestForUser($userId);
    }

    /**
     * bin/send_queued_notifications.php's own cron flush -- delivers
     * whatever's currently queued and clears the queue as it goes.
     * Bypasses the cooldown check entirely (that's the whole point: this
     * IS the delayed delivery), but still re-checks the relevant
     * preference (the user may have turned it off between queueing and
     * now) and still respects VAPID/subscription availability the same
     * way an ordinary send does. Still stamps last_notified_at forward on
     * an actual send, so a live event immediately afterward doesn't turn
     * right around and re-queue on top of what was just delivered.
     *
     * @return int how many notifications were actually sent
     */
    public function flushQueuedNotifications(): int
    {
        $sent = 0;

        foreach ($this->queuedNotifications->all() as $row) {
            $userId = $row['user_id'];

            if ($this->preferences->forUser($userId)[$row['preference_key']]) {
                $subscriptions = $this->subscriptions->listForUser($userId);
                $webPush = $subscriptions !== [] ? $this->webPush() : null;

                if ($webPush !== null) {
                    $this->preferences->markNotified($userId);
                    $this->sendNow($webPush, $subscriptions, [
                        'title' => $row['title'],
                        'body' => $row['body'],
                        'url' => $row['url'],
                        'tag' => $row['tag'],
                    ]);
                    $sent++;
                }
            }

            $this->queuedNotifications->deleteForUser($userId);
        }

        return $sent;
    }

    /**
     * @param array{title: string, body: string, url: string, tag: string} $payload
     */
    private function notify(int $userId, string $preferenceKey, array $payload): void
    {
        if (!$this->preferences->forUser($userId)[$preferenceKey]) {
            return;
        }

        if ($this->preferences->wasNotifiedRecently($userId, self::COOLDOWN_SECONDS)) {
            $this->queuedNotifications->enqueue($userId, $preferenceKey, $payload);
            return;
        }

        $subscriptions = $this->subscriptions->listForUser($userId);
        if ($subscriptions === []) {
            return;
        }

        $webPush = $this->webPush();
        if ($webPush === null) {
            return;
        }

        $this->preferences->markNotified($userId);

        // A live send supersedes anything still sitting in the queue from
        // earlier in this same cooldown window -- without this, a stale
        // queued reminder could still go out later even though the user
        // was just notified with something more current.
        $this->queuedNotifications->deleteForUser($userId);

        $this->sendNow($webPush, $subscriptions, $payload);
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
            if (!$report->isSuccess() && $report->isSubscriptionExpired()) {
                $this->pruneExpired($subscriptions, $report->getEndpoint());
            }
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
