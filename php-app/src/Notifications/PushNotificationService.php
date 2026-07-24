<?php

declare(strict_types=1);

namespace MoodSwings\Notifications;

use Minishlink\WebPush\ContentEncoding;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use MoodSwings\Config;
use MoodSwings\Repository\NotificationPreferenceRepository;
use MoodSwings\Repository\PushSubscriptionRepository;

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
 * notify() also enforces a global per-user cooldown (COOLDOWN_SECONDS,
 * see NotificationPreferenceRepository::wasNotifiedRecently()/
 * markNotified()) -- regardless of event type or which game it's about,
 * so a player actively working through several turns/decisions in a row
 * isn't sent one push per event.
 */
final class PushNotificationService
{
    private const COOLDOWN_SECONDS = 300;

    public function __construct(
        private readonly PushSubscriptionRepository $subscriptions,
        private readonly NotificationPreferenceRepository $preferences,
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

    /**
     * @param array{title: string, body: string, url: string, tag: string} $payload
     */
    private function notify(int $userId, string $preferenceKey, array $payload): void
    {
        if (!$this->preferences->forUser($userId)[$preferenceKey]) {
            return;
        }

        if ($this->preferences->wasNotifiedRecently($userId, self::COOLDOWN_SECONDS)) {
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
