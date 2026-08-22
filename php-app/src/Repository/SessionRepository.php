<?php

declare(strict_types=1);

namespace MoodSwings\Repository;

use DateTimeImmutable;
use MoodSwings\Database\Connection;

final class SessionRepository
{
    public function create(
        int $userId,
        string $tokenHash,
        DateTimeImmutable $expiresAt,
        ?string $ipAddress,
        ?string $userAgent
    ): void {
        $stmt = Connection::get()->prepare(
            'INSERT INTO sessions (user_id, token_hash, expires_at, ip_address, user_agent)
             VALUES (:user_id, :token_hash, :expires_at, :ip_address, :user_agent)'
        );
        $stmt->execute([
            'user_id' => $userId,
            'token_hash' => $tokenHash,
            'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
        ]);
    }

    public function findValidByTokenHash(string $tokenHash): ?array
    {
        $stmt = Connection::get()->prepare(
            'SELECT sessions.id, sessions.user_id, users.username, users.email, users.phone_number, users.share_presence,
                    users.default_selections_mode_preference, users.auto_pass_on_empty_hand, users.auto_apply_scoring_bonuses
             FROM sessions
             INNER JOIN users ON users.id = sessions.user_id
             WHERE sessions.token_hash = :token_hash AND sessions.expires_at > NOW()'
        );
        $stmt->execute(['token_hash' => $tokenHash]);
        $session = $stmt->fetch();

        return $session === false ? null : $session;
    }

    public function touch(int $sessionId, DateTimeImmutable $expiresAt): void
    {
        $stmt = Connection::get()->prepare(
            'UPDATE sessions SET last_seen_at = NOW(), expires_at = :expires_at WHERE id = :id'
        );
        $stmt->execute(['expires_at' => $expiresAt->format('Y-m-d H:i:s'), 'id' => $sessionId]);
    }

    public function deleteByTokenHash(string $tokenHash): void
    {
        $stmt = Connection::get()->prepare('DELETE FROM sessions WHERE token_hash = :token_hash');
        $stmt->execute(['token_hash' => $tokenHash]);
    }

    public function deleteAllForUser(int $userId): void
    {
        $stmt = Connection::get()->prepare('DELETE FROM sessions WHERE user_id = :user_id');
        $stmt->execute(['user_id' => $userId]);
    }

    /**
     * Online/presence indicator (issue #110) -- the most recent
     * last_seen_at among each user's own currently-valid (non-expired)
     * sessions, keyed by user_id. A user can be logged in on more than
     * one device/tab at once, so this is a MAX() across all of theirs,
     * not any single session row. Absent from the returned array
     * entirely means that user has no currently-valid session at all
     * (never logged in, or every session has since expired) -- see
     * PresenceService, the only caller, for how that's treated as
     * offline.
     *
     * @param int[] $userIds
     * @return array<int, string> user_id => last_seen_at ('Y-m-d H:i:s')
     */
    public function lastSeenAtForUsers(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $stmt = Connection::get()->prepare(
            "SELECT user_id, MAX(last_seen_at) AS last_seen_at FROM sessions
             WHERE user_id IN ({$placeholders}) AND expires_at > NOW()
             GROUP BY user_id"
        );
        $stmt->execute(array_values($userIds));

        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[(int) $row['user_id']] = $row['last_seen_at'];
        }

        return $result;
    }
}
