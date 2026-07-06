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
            'SELECT sessions.id, sessions.user_id, users.username, users.email, users.phone_number
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
}
