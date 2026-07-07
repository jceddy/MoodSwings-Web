<?php

declare(strict_types=1);

namespace MoodSwings\Repository;

use MoodSwings\Database\Connection;

final class FriendshipRepository
{
    public function create(int $userLowId, int $userHighId, string $status, int $actionUserId): void
    {
        $stmt = Connection::get()->prepare(
            'INSERT INTO friendships (user_low_id, user_high_id, status, action_user_id)
             VALUES (:user_low_id, :user_high_id, :status, :action_user_id)'
        );
        $stmt->execute([
            'user_low_id' => $userLowId,
            'user_high_id' => $userHighId,
            'status' => $status,
            'action_user_id' => $actionUserId,
        ]);
    }

    public function findByPair(int $userAId, int $userBId): ?array
    {
        [$low, $high] = $userAId < $userBId ? [$userAId, $userBId] : [$userBId, $userAId];

        $stmt = Connection::get()->prepare(
            'SELECT * FROM friendships WHERE user_low_id = :low AND user_high_id = :high'
        );
        $stmt->execute(['low' => $low, 'high' => $high]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function updateStatus(int $id, string $status, int $actionUserId): void
    {
        $stmt = Connection::get()->prepare(
            'UPDATE friendships SET status = :status, action_user_id = :action_user_id WHERE id = :id'
        );
        $stmt->execute(['status' => $status, 'action_user_id' => $actionUserId, 'id' => $id]);
    }

    public function delete(int $id): void
    {
        $stmt = Connection::get()->prepare('DELETE FROM friendships WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    public function listAcceptedForUser(int $userId): array
    {
        $stmt = Connection::get()->prepare(
            'SELECT
                f.id,
                CASE WHEN f.user_low_id = :user_id1 THEN f.user_high_id ELSE f.user_low_id END AS friend_id,
                u.username AS friend_username,
                f.created_at
             FROM friendships f
             JOIN users u ON u.id = CASE WHEN f.user_low_id = :user_id2 THEN f.user_high_id ELSE f.user_low_id END
             WHERE (f.user_low_id = :user_id3 OR f.user_high_id = :user_id4)
               AND f.status = \'accepted\'
             ORDER BY f.created_at DESC'
        );
        $stmt->execute([
            'user_id1' => $userId,
            'user_id2' => $userId,
            'user_id3' => $userId,
            'user_id4' => $userId,
        ]);

        return $stmt->fetchAll();
    }

    public function listIncomingPendingForUser(int $userId): array
    {
        return $this->listPendingForUser($userId, isActor: false);
    }

    public function listOutgoingPendingForUser(int $userId): array
    {
        return $this->listPendingForUser($userId, isActor: true);
    }

    private function listPendingForUser(int $userId, bool $isActor): array
    {
        $actorComparison = $isActor ? '=' : '!=';

        $stmt = Connection::get()->prepare(
            "SELECT
                f.id,
                CASE WHEN f.user_low_id = :user_id1 THEN f.user_high_id ELSE f.user_low_id END AS other_user_id,
                u.username AS other_username,
                f.created_at
             FROM friendships f
             JOIN users u ON u.id = CASE WHEN f.user_low_id = :user_id2 THEN f.user_high_id ELSE f.user_low_id END
             WHERE (f.user_low_id = :user_id3 OR f.user_high_id = :user_id4)
               AND f.status = 'pending'
               AND f.action_user_id {$actorComparison} :user_id5
             ORDER BY f.created_at DESC"
        );
        $stmt->execute([
            'user_id1' => $userId,
            'user_id2' => $userId,
            'user_id3' => $userId,
            'user_id4' => $userId,
            'user_id5' => $userId,
        ]);

        return $stmt->fetchAll();
    }
}
