<?php

declare(strict_types=1);

namespace MoodSwings\Repository;

use MoodSwings\Database\Connection;

final class OpenGameListingRepository
{
    public function create(int $createdByUserId, array $createGameParams): int
    {
        $stmt = Connection::get()->prepare(
            'INSERT INTO open_game_listings (created_by_user_id, create_game_params) VALUES (:created_by_user_id, :create_game_params)'
        );
        $stmt->execute([
            'created_by_user_id' => $createdByUserId,
            'create_game_params' => json_encode($createGameParams, JSON_THROW_ON_ERROR),
        ]);

        return (int) Connection::get()->lastInsertId();
    }

    public function find(int $id): ?array
    {
        $stmt = Connection::get()->prepare('SELECT * FROM open_game_listings WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $this->decode($row);
    }

    /**
     * Every currently-open listing visible to $viewerUserId: not the
     * viewer's own, its creator has opted into matchmaking_discoverable,
     * and neither side has blocked the other (friendships.status =
     * 'blocked' is symmetric once set regardless of who performed the
     * block, so this excludes the pair either direction).
     */
    public function listOpenFor(int $viewerUserId): array
    {
        $stmt = Connection::get()->prepare(
            "SELECT ogl.*, u.username AS creator_username
             FROM open_game_listings ogl
             JOIN users u ON u.id = ogl.created_by_user_id
             WHERE ogl.status = 'open'
               AND u.matchmaking_discoverable = 1
               AND ogl.created_by_user_id != :viewer_user_id
               AND NOT EXISTS (
                   SELECT 1 FROM friendships f
                   WHERE f.status = 'blocked'
                     AND f.user_low_id = LEAST(ogl.created_by_user_id, :viewer_user_id_low)
                     AND f.user_high_id = GREATEST(ogl.created_by_user_id, :viewer_user_id_high)
               )
             ORDER BY ogl.created_at ASC"
        );
        $stmt->execute([
            'viewer_user_id' => $viewerUserId,
            'viewer_user_id_low' => $viewerUserId,
            'viewer_user_id_high' => $viewerUserId,
        ]);

        return array_map($this->decode(...), $stmt->fetchAll());
    }

    public function listOpenCreatedBy(int $userId): array
    {
        $stmt = Connection::get()->prepare(
            "SELECT * FROM open_game_listings WHERE created_by_user_id = :user_id AND status = 'open' ORDER BY created_at ASC"
        );
        $stmt->execute(['user_id' => $userId]);

        return array_map($this->decode(...), $stmt->fetchAll());
    }

    public function markClaimed(int $id, int $claimedByUserId, int $claimedGameId): void
    {
        $stmt = Connection::get()->prepare(
            "UPDATE open_game_listings
             SET status = 'claimed', claimed_by_user_id = :claimed_by_user_id, claimed_game_id = :claimed_game_id, claimed_at = NOW()
             WHERE id = :id"
        );
        $stmt->execute(['claimed_by_user_id' => $claimedByUserId, 'claimed_game_id' => $claimedGameId, 'id' => $id]);
    }

    public function markCancelled(int $id): void
    {
        $stmt = Connection::get()->prepare(
            "UPDATE open_game_listings SET status = 'cancelled', cancelled_at = NOW() WHERE id = :id"
        );
        $stmt->execute(['id' => $id]);
    }

    private function decode(array $row): array
    {
        $row['create_game_params'] = json_decode((string) $row['create_game_params'], true, 512, JSON_THROW_ON_ERROR);

        return $row;
    }
}
