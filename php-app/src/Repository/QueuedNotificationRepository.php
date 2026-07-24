<?php

declare(strict_types=1);

namespace MoodSwings\Repository;

use MoodSwings\Database\Connection;
use MoodSwings\Notifications\NotificationScope;

final class QueuedNotificationRepository
{
    /**
     * Upserts $userId's one queued notification for this specific
     * $scope -- replacing whatever was previously queued for that same
     * (user, scope) pair, if anything (see migration 0049's own docblock
     * on why this is a one-row-per-(user, scope) replace-on-arrival queue
     * rather than an accumulating list). A notification queued for a
     * different scope -- another game, or a friend request -- is a
     * separate row entirely and is left untouched.
     *
     * @param array{title: string, body: string, url: string, tag: string} $payload
     */
    public function enqueue(int $userId, string $scope, string $preferenceKey, array $payload): void
    {
        Connection::get()->prepare(
            'INSERT INTO queued_notifications (user_id, scope, preference_key, title, body, url, tag, queued_at)
             VALUES (:user_id, :scope, :preference_key, :title, :body, :url, :tag, NOW())
             ON DUPLICATE KEY UPDATE
                preference_key = VALUES(preference_key),
                title = VALUES(title),
                body = VALUES(body),
                url = VALUES(url),
                tag = VALUES(tag),
                queued_at = VALUES(queued_at)'
        )->execute([
            'user_id' => $userId,
            'scope' => $scope,
            'preference_key' => $preferenceKey,
            'title' => $payload['title'],
            'body' => $payload['body'],
            'url' => $payload['url'],
            'tag' => $payload['tag'],
        ]);
    }

    /**
     * Every currently-queued notification, regardless of age -- used by
     * tests to inspect queue state. Production sending goes through
     * dueForFlush() instead, which only returns rows old enough to
     * actually flush.
     *
     * @return array<int, array{user_id: int, scope: string, preference_key: string, title: string, body: string, url: string, tag: string}>
     */
    public function all(): array
    {
        $rows = Connection::get()
            ->query('SELECT user_id, scope, preference_key, title, body, url, tag FROM queued_notifications')
            ->fetchAll();

        return self::mapRows($rows);
    }

    /**
     * Only the queued rows old enough to actually flush -- a row queued
     * more recently than $minAgeSeconds is left in place for a later
     * flush, rather than being delivered the moment a cron run happens to
     * land right after it was queued. This matters because clearing a
     * queued notification (see clearForGameIfMatches()/
     * clearFriendRequestForUser()) only happens when the player takes the
     * action it was reminding them about -- giving every queued
     * notification the same grace window the original cooldown itself
     * used (bin/send_queued_notifications.php passes
     * PushNotificationService::COOLDOWN_SECONDS here) means a player who
     * acts shortly after triggering the notification still gets a chance
     * to clear it before the cron ever sees it, instead of it going out
     * moments after being queued.
     *
     * @return array<int, array{user_id: int, scope: string, preference_key: string, title: string, body: string, url: string, tag: string}>
     */
    public function dueForFlush(int $minAgeSeconds): array
    {
        $stmt = Connection::get()->prepare(
            'SELECT user_id, scope, preference_key, title, body, url, tag
             FROM queued_notifications
             WHERE queued_at <= DATE_SUB(NOW(), INTERVAL :min_age_seconds SECOND)'
        );
        $stmt->execute(['min_age_seconds' => $minAgeSeconds]);

        return self::mapRows($stmt->fetchAll());
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array{user_id: int, scope: string, preference_key: string, title: string, body: string, url: string, tag: string}>
     */
    private static function mapRows(array $rows): array
    {
        return array_map(
            static fn (array $row): array => [
                'user_id' => (int) $row['user_id'],
                'scope' => $row['scope'],
                'preference_key' => $row['preference_key'],
                'title' => $row['title'],
                'body' => $row['body'],
                'url' => $row['url'],
                'tag' => $row['tag'],
            ],
            $rows
        );
    }

    /** Deletes $userId's queued notification for this specific $scope only, if any. */
    public function delete(int $userId, string $scope): void
    {
        Connection::get()->prepare(
            'DELETE FROM queued_notifications WHERE user_id = :user_id AND scope = :scope'
        )->execute(['user_id' => $userId, 'scope' => $scope]);
    }

    /**
     * Clears $userId's queued notification for $gameId specifically --
     * called when that player takes the action a "waiting on you"
     * reminder for this game would have been about (see
     * GameService::clearQueuedNotificationForGamePlayer()), so they never
     * get a stale nudge for something they've already done. A queued
     * notification for a different game, or a friend request, is a
     * different (user, scope) row entirely and is left untouched.
     */
    public function clearForGameIfMatches(int $userId, int $gameId): void
    {
        $this->delete($userId, NotificationScope::forGame($gameId));
    }

    /** Same idea as clearForGameIfMatches(), for the one non-game scope. */
    public function clearFriendRequestForUser(int $userId): void
    {
        $this->delete($userId, NotificationScope::FRIEND_REQUEST);
    }
}
