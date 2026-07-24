<?php

declare(strict_types=1);

namespace MoodSwings\Repository;

use DateTimeImmutable;
use MoodSwings\Database\Connection;

final class DiscordOAuthStateRepository
{
    public function create(string $stateHash, int $userId, DateTimeImmutable $expiresAt): void
    {
        Connection::get()->prepare(
            'INSERT INTO discord_oauth_states (state_hash, user_id, expires_at) VALUES (:state_hash, :user_id, :expires_at)'
        )->execute([
            'state_hash' => $stateHash,
            'user_id' => $userId,
            'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Single-use: a state row found here is deleted by the same call, so a
     * replayed/reused `state` value (a stale browser tab, a retried
     * callback) never validates twice.
     */
    public function consumeValid(string $stateHash): ?int
    {
        $pdo = Connection::get();

        $stmt = $pdo->prepare(
            'SELECT user_id FROM discord_oauth_states WHERE state_hash = :state_hash AND expires_at > NOW()'
        );
        $stmt->execute(['state_hash' => $stateHash]);
        $userId = $stmt->fetchColumn();

        $pdo->prepare('DELETE FROM discord_oauth_states WHERE state_hash = :state_hash')
            ->execute(['state_hash' => $stateHash]);

        return $userId !== false ? (int) $userId : null;
    }
}
