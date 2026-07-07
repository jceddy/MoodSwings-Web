<?php

declare(strict_types=1);

namespace MoodSwings\Repository;

use MoodSwings\Database\Connection;

/**
 * Read-only access to the `cards` reference table (see
 * database/migrations/0003_create_card_catalog.sql). This is catalog data
 * seeded by a migration, not written by the app, so there's no create/
 * update/delete here.
 */
final class CardRepository
{
    public function findById(int $id): ?array
    {
        $stmt = Connection::get()->prepare('SELECT * FROM cards WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function findByEffectKey(string $effectKey): ?array
    {
        $stmt = Connection::get()->prepare('SELECT * FROM cards WHERE effect_key = :effect_key');
        $stmt->execute(['effect_key' => $effectKey]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function all(): array
    {
        return Connection::get()->query('SELECT * FROM cards ORDER BY id')->fetchAll();
    }

    public function findByColor(string $color): array
    {
        $stmt = Connection::get()->prepare('SELECT * FROM cards WHERE color = :color ORDER BY id');
        $stmt->execute(['color' => $color]);

        return $stmt->fetchAll();
    }
}
