<?php

declare(strict_types=1);

namespace MoodSwings\Repository;

use MoodSwings\Database\Connection;

final class OpenGameListingRepository
{
    public function create(int $createdByUserId, array $createGameParams, int $targetPlayerCount): int
    {
        $stmt = Connection::get()->prepare(
            'INSERT INTO open_game_listings (created_by_user_id, create_game_params, target_player_count) VALUES (:created_by_user_id, :create_game_params, :target_player_count)'
        );
        $stmt->execute([
            'created_by_user_id' => $createdByUserId,
            'create_game_params' => json_encode($createGameParams, JSON_THROW_ON_ERROR),
            'target_player_count' => $targetPlayerCount,
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
     * neither side has blocked the other (friendships.status = 'blocked'
     * is symmetric once set regardless of who performed the block, so
     * this excludes the pair either direction), and the viewer hasn't
     * already joined it themselves (see listJoinedBy() for that case --
     * a "Join" button makes no sense for a listing already waiting on
     * this same viewer). joined_count is how many of target_player_count
     * seats (beyond the creator's own) are filled so far.
     */
    public function listOpenFor(int $viewerUserId): array
    {
        $stmt = Connection::get()->prepare(
            "SELECT ogl.*, u.username AS creator_username,
                    (SELECT COUNT(*) FROM open_game_listing_joins j WHERE j.listing_id = ogl.id) AS joined_count
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
               AND NOT EXISTS (
                   SELECT 1 FROM open_game_listing_joins j2 WHERE j2.listing_id = ogl.id AND j2.user_id = :viewer_user_id_joined
               )
             ORDER BY ogl.created_at ASC"
        );
        $stmt->execute([
            'viewer_user_id' => $viewerUserId,
            'viewer_user_id_low' => $viewerUserId,
            'viewer_user_id_high' => $viewerUserId,
            'viewer_user_id_joined' => $viewerUserId,
        ]);

        return array_map($this->decode(...), $stmt->fetchAll());
    }

    public function listOpenCreatedBy(int $userId): array
    {
        $stmt = Connection::get()->prepare(
            "SELECT ogl.*, (SELECT COUNT(*) FROM open_game_listing_joins j WHERE j.listing_id = ogl.id) AS joined_count
             FROM open_game_listings ogl
             WHERE ogl.created_by_user_id = :user_id AND ogl.status = 'open'
             ORDER BY ogl.created_at ASC"
        );
        $stmt->execute(['user_id' => $userId]);

        return array_map($this->decode(...), $stmt->fetchAll());
    }

    /**
     * Every still-open listing $userId has joined but that hasn't
     * started yet -- lets them see it's still waiting (and leave it, via
     * MatchmakingService::leaveOpenGame()) rather than only ever seeing
     * it disappear from listOpenFor() with no explanation.
     */
    public function listJoinedBy(int $userId): array
    {
        $stmt = Connection::get()->prepare(
            "SELECT ogl.*, u.username AS creator_username,
                    (SELECT COUNT(*) FROM open_game_listing_joins j WHERE j.listing_id = ogl.id) AS joined_count
             FROM open_game_listings ogl
             JOIN users u ON u.id = ogl.created_by_user_id
             JOIN open_game_listing_joins mine ON mine.listing_id = ogl.id AND mine.user_id = :user_id
             WHERE ogl.status = 'open'
             ORDER BY mine.joined_at ASC"
        );
        $stmt->execute(['user_id' => $userId]);

        return array_map($this->decode(...), $stmt->fetchAll());
    }

    /** @return int[] user ids, in the order they joined -- never includes the listing's own creator */
    public function joinedUserIds(int $listingId): array
    {
        $stmt = Connection::get()->prepare(
            'SELECT user_id FROM open_game_listing_joins WHERE listing_id = :listing_id ORDER BY joined_at ASC'
        );
        $stmt->execute(['listing_id' => $listingId]);

        return array_map(intval(...), $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function addJoin(int $listingId, int $userId): void
    {
        $stmt = Connection::get()->prepare(
            'INSERT INTO open_game_listing_joins (listing_id, user_id) VALUES (:listing_id, :user_id)'
        );
        $stmt->execute(['listing_id' => $listingId, 'user_id' => $userId]);
    }

    public function removeJoin(int $listingId, int $userId): void
    {
        $stmt = Connection::get()->prepare(
            'DELETE FROM open_game_listing_joins WHERE listing_id = :listing_id AND user_id = :user_id'
        );
        $stmt->execute(['listing_id' => $listingId, 'user_id' => $userId]);
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
