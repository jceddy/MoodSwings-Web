<?php

declare(strict_types=1);

namespace MoodSwings\Repository;

use DateTimeImmutable;
use MoodSwings\Database\Connection;

final class PasswordResetRepository
{
    public function create(int $userId, string $tokenHash, DateTimeImmutable $expiresAt): void
    {
        $stmt = Connection::get()->prepare(
            'INSERT INTO password_resets (user_id, token_hash, expires_at)
             VALUES (:user_id, :token_hash, :expires_at)'
        );
        $stmt->execute([
            'user_id' => $userId,
            'token_hash' => $tokenHash,
            'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
        ]);
    }

    public function mostRecentCreatedAtForUser(int $userId): ?DateTimeImmutable
    {
        $stmt = Connection::get()->prepare(
            'SELECT created_at FROM password_resets
             WHERE user_id = :user_id ORDER BY created_at DESC LIMIT 1'
        );
        $stmt->execute(['user_id' => $userId]);
        $row = $stmt->fetch();

        return $row === false ? null : new DateTimeImmutable($row['created_at']);
    }

    public function deleteAllForUser(int $userId): void
    {
        $stmt = Connection::get()->prepare('DELETE FROM password_resets WHERE user_id = :user_id');
        $stmt->execute(['user_id' => $userId]);
    }

    /**
     * Single-use: a token row found here is deleted by the same call, so a
     * replayed/reused reset link (a stale browser tab, a retried submit,
     * a pre-fetching email scanner) never validates twice.
     */
    public function consumeValid(string $tokenHash): ?int
    {
        $pdo = Connection::get();

        $stmt = $pdo->prepare(
            'SELECT user_id FROM password_resets WHERE token_hash = :token_hash AND expires_at > NOW()'
        );
        $stmt->execute(['token_hash' => $tokenHash]);
        $userId = $stmt->fetchColumn();

        $pdo->prepare('DELETE FROM password_resets WHERE token_hash = :token_hash')
            ->execute(['token_hash' => $tokenHash]);

        return $userId !== false ? (int) $userId : null;
    }
}
