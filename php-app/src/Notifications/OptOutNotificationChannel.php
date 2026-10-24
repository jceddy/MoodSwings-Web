<?php

declare(strict_types=1);

namespace MoodSwings\Notifications;

/**
 * A NotificationChannel a user can switch off on its own, independent of
 * every other channel -- NotificationService::deliver() skips it for a
 * user whose preference named by preferenceKey() is off, while still
 * delivering to their other channels. Push (web and Android) deliberately
 * doesn't implement this: its own opt-out is simply not having a
 * subscription/device registered.
 */
interface OptOutNotificationChannel extends NotificationChannel
{
    /** The notification_preferences flag (see NotificationPreferenceRepository::forUser()) that must be true for this channel to be tried. */
    public function preferenceKey(): string;
}
