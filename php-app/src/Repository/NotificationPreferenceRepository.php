<?php

declare(strict_types=1);

namespace MoodSwings\Repository;

use MoodSwings\Database\Connection;

final class NotificationPreferenceRepository
{
    /**
     * All-true defaults for a user who's never touched their preferences
     * (no row yet) -- see migration 0048's docblock: a row is only ever
     * written once a user actually changes a setting.
     *
     * @return array{notify_your_turn: bool, notify_friend_request: bool, notify_game_finished: bool}
     */
    public function forUser(int $userId): array
    {
        $stmt = Connection::get()->prepare(
            'SELECT notify_your_turn, notify_friend_request, notify_game_finished
             FROM notification_preferences WHERE user_id = :user_id'
        );
        $stmt->execute(['user_id' => $userId]);
        $row = $stmt->fetch();

        if ($row === false) {
            return ['notify_your_turn' => true, 'notify_friend_request' => true, 'notify_game_finished' => true];
        }

        return [
            'notify_your_turn' => (bool) $row['notify_your_turn'],
            'notify_friend_request' => (bool) $row['notify_friend_request'],
            'notify_game_finished' => (bool) $row['notify_game_finished'],
        ];
    }

    public function save(int $userId, bool $notifyYourTurn, bool $notifyFriendRequest, bool $notifyGameFinished): void
    {
        $stmt = Connection::get()->prepare(
            'INSERT INTO notification_preferences (user_id, notify_your_turn, notify_friend_request, notify_game_finished)
             VALUES (:user_id, :your_turn, :friend_request, :game_finished)
             ON DUPLICATE KEY UPDATE
                notify_your_turn = VALUES(notify_your_turn),
                notify_friend_request = VALUES(notify_friend_request),
                notify_game_finished = VALUES(notify_game_finished)'
        );
        $stmt->execute([
            'user_id' => $userId,
            'your_turn' => $notifyYourTurn ? 1 : 0,
            'friend_request' => $notifyFriendRequest ? 1 : 0,
            'game_finished' => $notifyGameFinished ? 1 : 0,
        ]);
    }

    /**
     * PushNotificationService's global per-user cooldown -- true if
     * $userId was already sent a push (any event type, any game) within
     * the last $withinSeconds. A user with no row at all has obviously
     * never been notified.
     */
    public function wasNotifiedRecently(int $userId, int $withinSeconds): bool
    {
        $stmt = Connection::get()->prepare(
            'SELECT 1 FROM notification_preferences
             WHERE user_id = :user_id
               AND last_notified_at IS NOT NULL
               AND last_notified_at > (NOW() - INTERVAL :within_seconds SECOND)'
        );
        $stmt->execute(['user_id' => $userId, 'within_seconds' => $withinSeconds]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Stamps $userId's cooldown window forward to now -- an upsert so the
     * very first notification ever sent to a user (who may never have
     * touched their preferences) still creates the row, with the
     * notify_* columns falling back to their schema defaults (all true)
     * rather than this write clobbering preferences that were never set.
     */
    public function markNotified(int $userId): void
    {
        Connection::get()->prepare(
            'INSERT INTO notification_preferences (user_id, last_notified_at)
             VALUES (:user_id, NOW())
             ON DUPLICATE KEY UPDATE last_notified_at = NOW()'
        )->execute(['user_id' => $userId]);
    }
}
