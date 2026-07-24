<?php

declare(strict_types=1);

namespace MoodSwings\Repository;

use MoodSwings\Database\Connection;

final class QueuedNotificationRepository
{
    /**
     * Upserts $userId's one queued notification -- replacing whatever was
     * previously queued for them, if anything (see migration 0048's own
     * docblock on why this is a one-row-per-user replace-on-arrival queue
     * rather than an accumulating list).
     *
     * @param array{title: string, body: string, url: string, tag: string} $payload
     */
    public function enqueue(int $userId, string $preferenceKey, array $payload): void
    {
        Connection::get()->prepare(
            'INSERT INTO queued_notifications (user_id, preference_key, title, body, url, tag, queued_at)
             VALUES (:user_id, :preference_key, :title, :body, :url, :tag, NOW())
             ON DUPLICATE KEY UPDATE
                preference_key = VALUES(preference_key),
                title = VALUES(title),
                body = VALUES(body),
                url = VALUES(url),
                tag = VALUES(tag),
                queued_at = VALUES(queued_at)'
        )->execute([
            'user_id' => $userId,
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
     * @return array<int, array{user_id: int, preference_key: string, title: string, body: string, url: string, tag: string}>
     */
    public function all(): array
    {
        $rows = Connection::get()
            ->query('SELECT user_id, preference_key, title, body, url, tag FROM queued_notifications')
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
     * @return array<int, array{user_id: int, preference_key: string, title: string, body: string, url: string, tag: string}>
     */
    public function dueForFlush(int $minAgeSeconds): array
    {
        $stmt = Connection::get()->prepare(
            'SELECT user_id, preference_key, title, body, url, tag
             FROM queued_notifications
             WHERE queued_at <= DATE_SUB(NOW(), INTERVAL :min_age_seconds SECOND)'
        );
        $stmt->execute(['min_age_seconds' => $minAgeSeconds]);

        return self::mapRows($stmt->fetchAll());
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array{user_id: int, preference_key: string, title: string, body: string, url: string, tag: string}>
     */
    private static function mapRows(array $rows): array
    {
        return array_map(
            static fn (array $row): array => [
                'user_id' => (int) $row['user_id'],
                'preference_key' => $row['preference_key'],
                'title' => $row['title'],
                'body' => $row['body'],
                'url' => $row['url'],
                'tag' => $row['tag'],
            ],
            $rows
        );
    }

    public function deleteForUser(int $userId): void
    {
        Connection::get()->prepare('DELETE FROM queued_notifications WHERE user_id = :user_id')->execute(['user_id' => $userId]);
    }

    /**
     * Clears $userId's queued notification only if it's tagged for
     * $gameId specifically -- called when that player takes the action a
     * "waiting on you" reminder for this game would have been about (see
     * GameService::clearQueuedNotificationForGamePlayer()), so they never
     * get a stale nudge for something they've already done. A queued
     * notification about a different game, or a friend request, is left
     * untouched. Tags are always literally "game-{id}-{suffix}" (see
     * PushNotificationService::notifyYourTurn()), so anchoring the LIKE
     * pattern immediately after the id can't cross-match a different
     * game id sharing the same prefix (e.g. game-4 vs game-42).
     */
    public function clearForGameIfMatches(int $userId, int $gameId): void
    {
        Connection::get()->prepare(
            'DELETE FROM queued_notifications WHERE user_id = :user_id AND tag LIKE :tag_prefix'
        )->execute(['user_id' => $userId, 'tag_prefix' => "game-{$gameId}-%"]);
    }

    /** Same idea as clearForGameIfMatches(), for the one non-game-scoped tag ('friend-request'). */
    public function clearFriendRequestForUser(int $userId): void
    {
        Connection::get()->prepare(
            "DELETE FROM queued_notifications WHERE user_id = :user_id AND tag = 'friend-request'"
        )->execute(['user_id' => $userId]);
    }
}
