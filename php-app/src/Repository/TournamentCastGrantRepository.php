<?php

declare(strict_types=1);

namespace MoodSwings\Repository;

use MoodSwings\Database\Connection;

/**
 * Tournament spectator mode (issue #238): who besides a tournament's own
 * creator (tournaments.created_by_user_id, always implicitly trusted --
 * see TournamentService::hasCastAccess()) has been explicitly granted
 * live, hands-revealed viewing of that tournament's still-in_progress
 * matches.
 */
final class TournamentCastGrantRepository
{
    public function add(int $tournamentId, int $userId, int $grantedByUserId): int
    {
        $stmt = Connection::get()->prepare(
            'INSERT INTO tournament_cast_grants (tournament_id, user_id, granted_by_user_id)
             VALUES (:tournament_id, :user_id, :granted_by_user_id)'
        );
        $stmt->execute([
            'tournament_id' => $tournamentId,
            'user_id' => $userId,
            'granted_by_user_id' => $grantedByUserId,
        ]);

        return (int) Connection::get()->lastInsertId();
    }

    public function find(int $tournamentId, int $userId): ?array
    {
        $stmt = Connection::get()->prepare(
            'SELECT * FROM tournament_cast_grants WHERE tournament_id = :tournament_id AND user_id = :user_id'
        );
        $stmt->execute(['tournament_id' => $tournamentId, 'user_id' => $userId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** @return array[] every grant for this tournament, joined to the grantee's username, oldest first */
    public function listForTournament(int $tournamentId): array
    {
        $stmt = Connection::get()->prepare(
            'SELECT tcg.*, u.username
             FROM tournament_cast_grants tcg
             JOIN users u ON u.id = tcg.user_id
             WHERE tcg.tournament_id = :tournament_id
             ORDER BY tcg.created_at ASC'
        );
        $stmt->execute(['tournament_id' => $tournamentId]);

        return $stmt->fetchAll();
    }

    public function remove(int $tournamentId, int $userId): void
    {
        Connection::get()
            ->prepare('DELETE FROM tournament_cast_grants WHERE tournament_id = :tournament_id AND user_id = :user_id')
            ->execute(['tournament_id' => $tournamentId, 'user_id' => $userId]);
    }
}
