<?php

declare(strict_types=1);

namespace MoodSwings\Repository;

use MoodSwings\Database\Connection;

/**
 * Backs PushNotificationService::notify()'s 5-minute cooldown, scoped per
 * (user, NotificationScope) rather than globally per user -- see
 * migration 0049's own docblock for why this replaced
 * notification_preferences.last_notified_at's single per-user timestamp.
 */
final class NotificationCooldownRepository
{
    /**
     * True if $userId was already sent a push for this specific $scope
     * within the last $withinSeconds. A user with no row for this scope
     * at all has obviously never been notified about it.
     */
    public function wasNotifiedRecently(int $userId, string $scope, int $withinSeconds): bool
    {
        $stmt = Connection::get()->prepare(
            'SELECT 1 FROM notification_cooldowns
             WHERE user_id = :user_id
               AND scope = :scope
               AND last_notified_at > (NOW() - INTERVAL :within_seconds SECOND)'
        );
        $stmt->execute(['user_id' => $userId, 'scope' => $scope, 'within_seconds' => $withinSeconds]);

        return $stmt->fetchColumn() !== false;
    }

    /** Stamps $userId's $scope cooldown window forward to now. */
    public function markNotified(int $userId, string $scope): void
    {
        Connection::get()->prepare(
            'INSERT INTO notification_cooldowns (user_id, scope, last_notified_at)
             VALUES (:user_id, :scope, NOW())
             ON DUPLICATE KEY UPDATE last_notified_at = NOW()'
        )->execute(['user_id' => $userId, 'scope' => $scope]);
    }
}
