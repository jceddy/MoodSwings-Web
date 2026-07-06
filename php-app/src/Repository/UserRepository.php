<?php

declare(strict_types=1);

namespace MoodSwings\Repository;

use MoodSwings\Database\Connection;

final class UserRepository
{
    public function create(string $username, string $passwordHash): array
    {
        $pdo = Connection::get();
        $stmt = $pdo->prepare('INSERT INTO users (username, password_hash) VALUES (:username, :password_hash)');
        $stmt->execute(['username' => $username, 'password_hash' => $passwordHash]);

        return $this->findById((int) $pdo->lastInsertId());
    }

    public function findByUsername(string $username): ?array
    {
        $stmt = Connection::get()->prepare('SELECT * FROM users WHERE username = :username');
        $stmt->execute(['username' => $username]);
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
}
