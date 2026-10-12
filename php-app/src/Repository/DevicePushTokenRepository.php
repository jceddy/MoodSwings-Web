<?php

declare(strict_types=1);

namespace MoodSwings\Repository;

use MoodSwings\Database\Connection;

/**
 * FCM registration tokens of the native app installs a user has signed in on (migration 0451) -- the native
 * counterpart of PushSubscriptionRepository's browser subscriptions.
 */
final class DevicePushTokenRepository
{
    public const PLATFORMS = ['android', 'ios'];

    /**
     * Upserts on token_hash: registering the same install again (a refreshed token that happens to repeat, or the
     * app signed in as a different user) moves the row to this user instead of piling up duplicates.
     */
    public function save(int $userId, string $platform, string $token): void
    {
        $stmt = Connection::get()->prepare(
            'INSERT INTO device_push_tokens (user_id, platform, token, token_hash)
             VALUES (:user_id, :platform, :token, :token_hash)
             ON DUPLICATE KEY UPDATE
                user_id = VALUES(user_id),
                platform = VALUES(platform),
                token = VALUES(token)'
        );
        $stmt->execute([
            'user_id' => $userId,
            'platform' => $platform,
            'token' => $token,
            'token_hash' => hash('sha256', $token),
        ]);
    }

    /** Signing out of the app, or turning notifications off there: only the caller's own row can be removed. */
    public function deleteByToken(int $userId, string $token): void
    {
        $stmt = Connection::get()->prepare(
            'DELETE FROM device_push_tokens WHERE user_id = :user_id AND token_hash = :token_hash'
        );
        $stmt->execute(['user_id' => $userId, 'token_hash' => hash('sha256', $token)]);
    }

    /**
     * @return array<int, array{id: int, platform: string, token: string}>
     */
    public function listForUser(int $userId): array
    {
        $stmt = Connection::get()->prepare(
            'SELECT id, platform, token FROM device_push_tokens WHERE user_id = :user_id'
        );
        $stmt->execute(['user_id' => $userId]);

        return $stmt->fetchAll();
    }

    /** Called when FCM reports a token is no longer valid (the app was uninstalled, or the token rotated). */
    public function deleteById(int $id): void
    {
        $stmt = Connection::get()->prepare('DELETE FROM device_push_tokens WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }
}
