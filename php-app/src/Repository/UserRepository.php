<?php

declare(strict_types=1);

namespace MoodSwings\Repository;

use MoodSwings\Database\Connection;

final class UserRepository
{
    public function create(string $username, string $email, string $passwordHash, ?string $phoneNumber): array
    {
        $pdo = Connection::get();
        $stmt = $pdo->prepare(
            'INSERT INTO users (username, email, phone_number, password_hash)
             VALUES (:username, :email, :phone_number, :password_hash)'
        );
        $stmt->execute([
            'username' => $username,
            'email' => $email,
            'phone_number' => $phoneNumber,
            'password_hash' => $passwordHash,
        ]);

        return $this->findById((int) $pdo->lastInsertId());
    }

    public function findByUsername(string $username): ?array
    {
        $stmt = Connection::get()->prepare('SELECT * FROM users WHERE username = :username');
        $stmt->execute(['username' => $username]);
        $user = $stmt->fetch();

        return $user === false ? null : $user;
    }

    public function findByEmail(string $email): ?array
    {
        $stmt = Connection::get()->prepare('SELECT * FROM users WHERE email = :email');
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();

        return $user === false ? null : $user;
    }

    public function findById(int $id): ?array
    {
        $stmt = Connection::get()->prepare('SELECT * FROM users WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $user = $stmt->fetch();

        return $user === false ? null : $user;
    }

    public function markEmailVerified(int $id): void
    {
        $stmt = Connection::get()->prepare('UPDATE users SET email_verified_at = NOW() WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    public function delete(int $id): void
    {
        $stmt = Connection::get()->prepare('DELETE FROM users WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }
}
