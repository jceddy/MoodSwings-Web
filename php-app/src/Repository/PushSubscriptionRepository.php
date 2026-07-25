<?php

declare(strict_types=1);

namespace MoodSwings\Repository;

use MoodSwings\Database\Connection;

final class PushSubscriptionRepository
{
    /**
     * Upserts on endpoint_hash: re-subscribing the same browser (its keys
     * can rotate) updates the existing row in place instead of piling up
     * duplicates for one physical subscription -- see migration 0048's own
     * docblock for why endpoint_hash, not endpoint, carries the uniqueness
     * constraint.
     */
    public function save(int $userId, string $endpoint, string $p256dhKey, string $authKey): void
    {
        $stmt = Connection::get()->prepare(
            'INSERT INTO push_subscriptions (user_id, endpoint, endpoint_hash, p256dh_key, auth_key)
             VALUES (:user_id, :endpoint, :endpoint_hash, :p256dh_key, :auth_key)
             ON DUPLICATE KEY UPDATE
                user_id = VALUES(user_id),
                endpoint = VALUES(endpoint),
                p256dh_key = VALUES(p256dh_key),
                auth_key = VALUES(auth_key)'
        );
        $stmt->execute([
            'user_id' => $userId,
            'endpoint' => $endpoint,
            'endpoint_hash' => hash('sha256', $endpoint),
            'p256dh_key' => $p256dhKey,
            'auth_key' => $authKey,
        ]);
    }

    public function deleteByEndpoint(int $userId, string $endpoint): void
    {
        $stmt = Connection::get()->prepare(
            'DELETE FROM push_subscriptions WHERE user_id = :user_id AND endpoint_hash = :endpoint_hash'
        );
        $stmt->execute(['user_id' => $userId, 'endpoint_hash' => hash('sha256', $endpoint)]);
    }

    /**
     * @return array<int, array{id: int, endpoint: string, p256dh_key: string, auth_key: string}>
     */
    public function listForUser(int $userId): array
    {
        $stmt = Connection::get()->prepare(
            'SELECT id, endpoint, p256dh_key, auth_key FROM push_subscriptions WHERE user_id = :user_id'
        );
        $stmt->execute(['user_id' => $userId]);

        return $stmt->fetchAll();
    }

    /**
     * Called when a push service reports a subscription is gone for good
     * (HTTP 404/410 -- the user uninstalled, cleared site data, or the
     * browser itself dropped it) so it stops being retried forever.
     */
    public function deleteById(int $id): void
    {
        $stmt = Connection::get()->prepare('DELETE FROM push_subscriptions WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }
}
