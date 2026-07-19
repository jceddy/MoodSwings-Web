<?php

declare(strict_types=1);

namespace MoodSwings\Repository;

use MoodSwings\Database\Connection;

final class UserDecklistRepository
{
    /**
     * @param int[] $cardIds
     * @param ?int[] $sideboardCardIds
     */
    public function create(int $userId, string $name, array $cardIds, ?array $sideboardCardIds, string $visibility): int
    {
        $stmt = Connection::get()->prepare(
            'INSERT INTO user_decklists (user_id, name, card_ids, sideboard_card_ids, visibility)
             VALUES (:user_id, :name, :card_ids, :sideboard_card_ids, :visibility)'
        );
        $stmt->execute([
            'user_id' => $userId,
            'name' => $name,
            'card_ids' => json_encode($cardIds),
            'sideboard_card_ids' => $sideboardCardIds !== null ? json_encode($sideboardCardIds) : null,
            'visibility' => $visibility,
        ]);

        return (int) Connection::get()->lastInsertId();
    }

    public function find(int $id): ?array
    {
        $stmt = Connection::get()->prepare('SELECT * FROM user_decklists WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @param int[] $cardIds
     * @param ?int[] $sideboardCardIds
     */
    public function update(int $id, string $name, array $cardIds, ?array $sideboardCardIds, string $visibility): void
    {
        $stmt = Connection::get()->prepare(
            'UPDATE user_decklists
             SET name = :name, card_ids = :card_ids, sideboard_card_ids = :sideboard_card_ids, visibility = :visibility
             WHERE id = :id'
        );
        $stmt->execute([
            'name' => $name,
            'card_ids' => json_encode($cardIds),
            'sideboard_card_ids' => $sideboardCardIds !== null ? json_encode($sideboardCardIds) : null,
            'visibility' => $visibility,
            'id' => $id,
        ]);
    }

    public function delete(int $id): void
    {
        $stmt = Connection::get()->prepare('DELETE FROM user_decklists WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    public function listForUser(int $userId): array
    {
        $stmt = Connection::get()->prepare('SELECT * FROM user_decklists WHERE user_id = :user_id ORDER BY name ASC');
        $stmt->execute(['user_id' => $userId]);

        return $stmt->fetchAll();
    }

    /**
     * Every friends-visible decklist belonging to any of the given user
     * ids -- used by UserDecklistService::listForViewer() to build the
     * "friends' decks" section, grouped by owner in PHP.
     *
     * @param int[] $friendUserIds
     */
    public function listFriendsVisibleForUsers(array $friendUserIds): array
    {
        if ($friendUserIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($friendUserIds), '?'));
        $stmt = Connection::get()->prepare(
            "SELECT ud.*, u.username AS owner_username
             FROM user_decklists ud
             JOIN users u ON u.id = ud.user_id
             WHERE ud.user_id IN ({$placeholders}) AND ud.visibility = 'friends'
             ORDER BY u.username ASC, ud.name ASC"
        );
        $stmt->execute($friendUserIds);

        return $stmt->fetchAll();
    }
}
