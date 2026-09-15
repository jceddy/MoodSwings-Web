<?php

declare(strict_types=1);

namespace MoodSwings\Repository;

use MoodSwings\Database\Connection;

final class TournamentParticipantRepository
{
    public function add(int $tournamentId, int $userId, string $status): int
    {
        $stmt = Connection::get()->prepare(
            "INSERT INTO tournament_participants (tournament_id, user_id, status, joined_at)
             VALUES (:tournament_id, :user_id, :status, IF(:status_for_joined_at = 'joined', NOW(), NULL))"
        );
        $stmt->execute([
            'tournament_id' => $tournamentId,
            'user_id' => $userId,
            'status' => $status,
            'status_for_joined_at' => $status,
        ]);

        return (int) Connection::get()->lastInsertId();
    }

    public function find(int $id): ?array
    {
        $stmt = Connection::get()->prepare('SELECT * FROM tournament_participants WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function findForUser(int $tournamentId, int $userId): ?array
    {
        $stmt = Connection::get()->prepare(
            'SELECT * FROM tournament_participants WHERE tournament_id = :tournament_id AND user_id = :user_id'
        );
        $stmt->execute(['tournament_id' => $tournamentId, 'user_id' => $userId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** @return array[] every row for this tournament, joined to the participant's username, ordered by seed then join time */
    public function listForTournament(int $tournamentId): array
    {
        $stmt = Connection::get()->prepare(
            'SELECT tp.*, u.username
             FROM tournament_participants tp
             JOIN users u ON u.id = tp.user_id
             WHERE tp.tournament_id = :tournament_id
             ORDER BY tp.seed IS NULL, tp.seed ASC, tp.created_at ASC'
        );
        $stmt->execute(['tournament_id' => $tournamentId]);

        return $stmt->fetchAll();
    }

    public function updateStatus(int $id, string $status): void
    {
        $stmt = Connection::get()->prepare(
            "UPDATE tournament_participants SET status = :status, joined_at = IF(:status_for_joined_at = 'joined', COALESCE(joined_at, NOW()), joined_at) WHERE id = :id"
        );
        $stmt->execute(['status' => $status, 'status_for_joined_at' => $status, 'id' => $id]);
    }

    public function setSeed(int $id, int $seed): void
    {
        Connection::get()
            ->prepare('UPDATE tournament_participants SET seed = :seed WHERE id = :id')
            ->execute(['seed' => $seed, 'id' => $id]);
    }
}
