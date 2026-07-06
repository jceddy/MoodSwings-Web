<?php

declare(strict_types=1);

namespace MoodSwings\Repository;

use DateTimeImmutable;
use MoodSwings\Database\Connection;

final class EmailVerificationRepository
{
    public function create(int $userId, string $tokenHash, DateTimeImmutable $expiresAt): void
    {
        $stmt = Connection::get()->prepare(
            'INSERT INTO email_verifications (user_id, token_hash, expires_at)
             VALUES (:user_id, :token_hash, :expires_at)'
        );
        $stmt->execute([
            'user_id' => $userId,
            'token_hash' => $tokenHash,
            'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
        ]);
    }

    public function findValidByTokenHash(string $tokenHash): ?array
    {
        $stmt = Connection::get()->prepare(
            'SELECT id, user_id FROM email_verifications
             WHERE token_hash = :token_hash AND expires_at > NOW()'
        );
        $stmt->execute(['token_hash' => $tokenHash]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function deleteAllForUser(int $userId): void
    {
        $stmt = Connection::get()->prepare('DELETE FROM email_verifications WHERE user_id = :user_id');
        $stmt->execute(['user_id' => $userId]);
    }
}
