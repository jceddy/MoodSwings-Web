<?php

declare(strict_types=1);

namespace MoodSwings\Notifications;

/**
 * One delivery mechanism NotificationService can fan a notification out
 * to (PushNotificationChannel, Discord\DiscordNotificationChannel) --
 * NotificationService itself owns the shared preference/cooldown/queue
 * decision of WHETHER to notify at all; each channel only decides HOW,
 * for a user who's already cleared that decision.
 */
interface NotificationChannel
{
    /**
     * Attempts delivery to $userId. Returns true if this channel actually
     * attempted a send (used by NotificationService to decide whether the
     * shared cooldown gets stamped for this event) -- false for a pure
     * no-op (nothing configured for this channel at all, or this specific
     * user has nothing to deliver to, e.g. no push subscription/no linked
     * Discord account). A false return must never be treated as a
     * failure: with more than one channel configured, one channel having
     * nothing to do is normal, not exceptional.
     *
     * Implementations must not let a delivery failure escape as a thrown
     * exception under normal operation (a network error, an expired
     * subscription, an API error) -- log it and return accordingly
     * instead, the same "never fail the request that triggered this"
     * guarantee NotificationService itself promises. NotificationService
     * still wraps every call in a try/catch as a last-resort safety net,
     * but that's a backstop, not the primary contract.
     *
     * @param array{title: string, body: string, url: string, tag: string} $payload
     */
    public function send(int $userId, array $payload): bool;
}
