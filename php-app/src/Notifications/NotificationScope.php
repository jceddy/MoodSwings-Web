<?php

declare(strict_types=1);

namespace MoodSwings\Notifications;

/**
 * The unit the 5-minute cooldown and its queue-and-replace fallback
 * (PushNotificationService::notify()) operate on -- coarser than a
 * notification's own `tag` (which further distinguishes turn/decision/
 * team-decision/etc. within the same game): every it's-your-turn or
 * game-finished notification about the SAME game shares one scope, so a
 * player active in several games at once can still be notified about
 * each of them within the same 5 minutes, but never more than once every
 * 5 minutes about any one specific game. Friend requests aren't tied to
 * any game at all, so they get their own fixed scope instead.
 *
 * Always a non-empty string -- both notification_cooldowns and
 * queued_notifications key on (user_id, scope) as part of their PRIMARY
 * KEY, which can never contain NULL, so "no specific game" has to be a
 * real constant rather than an absent/null game id.
 */
final class NotificationScope
{
    public const FRIEND_REQUEST = 'friend_request';

    public static function forGame(int $gameId): string
    {
        return "game:{$gameId}";
    }
}
