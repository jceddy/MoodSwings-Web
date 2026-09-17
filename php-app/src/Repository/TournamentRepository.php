<?php

declare(strict_types=1);

namespace MoodSwings\Repository;

use MoodSwings\Database\Connection;

final class TournamentRepository
{
    public function create(
        int $createdByUserId,
        string $name,
        string $bracketType,
        string $registrationMode,
        array $matchParams,
        ?int $swissRoundCount,
        int $minParticipants,
        ?int $maxParticipants,
    ): int {
        $stmt = Connection::get()->prepare(
            'INSERT INTO tournaments
                (created_by_user_id, name, bracket_type, registration_mode, match_params, swiss_round_count, min_participants, max_participants)
             VALUES (:created_by_user_id, :name, :bracket_type, :registration_mode, :match_params, :swiss_round_count, :min_participants, :max_participants)'
        );
        $stmt->execute([
            'created_by_user_id' => $createdByUserId,
            'name' => $name,
            'bracket_type' => $bracketType,
            'registration_mode' => $registrationMode,
            'match_params' => json_encode($matchParams, JSON_THROW_ON_ERROR),
            'swiss_round_count' => $swissRoundCount,
            'min_participants' => $minParticipants,
            'max_participants' => $maxParticipants,
        ]);

        return (int) Connection::get()->lastInsertId();
    }

    public function find(int $id): ?array
    {
        $stmt = Connection::get()->prepare('SELECT * FROM tournaments WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $this->decode($row);
    }

    /**
     * Every tournament $userId either created, is a participant in, or
     * was invited to -- still in registration or already under way/
     * completed. my_participant_status/my_participant_id are the
     * viewer's own tournament_participants row (NULL for a tournament
     * they only created but never joined themselves) -- the frontend
     * needs this to know whether to offer Accept/Decline, Withdraw, or
     * nothing at all, without a separate per-tournament lookup.
     * winner_username (reported live: show the winner on the
     * tournaments display) is NULL until a tournament actually reaches
     * 'completed' -- winner_user_id itself stays NULL until then too.
     */
    public function listForUser(int $userId): array
    {
        $stmt = Connection::get()->prepare(
            'SELECT DISTINCT t.*, tp.id AS my_participant_id, tp.status AS my_participant_status, w.username AS winner_username
             FROM tournaments t
             LEFT JOIN tournament_participants tp ON tp.tournament_id = t.id AND tp.user_id = :user_id
             LEFT JOIN users w ON w.id = t.winner_user_id
             WHERE t.created_by_user_id = :user_id_created OR tp.id IS NOT NULL
             ORDER BY t.created_at DESC'
        );
        $stmt->execute(['user_id' => $userId, 'user_id_created' => $userId]);

        return array_map($this->decode(...), $stmt->fetchAll());
    }

    /**
     * Every 'open' tournament still in 'registration' that $viewerUserId
     * could join -- not already an active participant (a 'withdrawn' row
     * doesn't count -- TournamentService::joinOpenTournament() lets you
     * rejoin one of those, room permitting, so it must still surface
     * here or there'd be no way back in), and (same discoverability gate
     * open_game_listings itself uses) the creator has opted into
     * matchmaking_discoverable and neither side has blocked the other.
     */
    public function listOpenFor(int $viewerUserId): array
    {
        $stmt = Connection::get()->prepare(
            "SELECT t.*, u.username AS creator_username,
                    (SELECT COUNT(*) FROM tournament_participants tp WHERE tp.tournament_id = t.id AND tp.status = 'joined') AS joined_count
             FROM tournaments t
             JOIN users u ON u.id = t.created_by_user_id
             WHERE t.registration_mode = 'open'
               AND t.status = 'registration'
               AND u.matchmaking_discoverable = 1
               AND t.created_by_user_id != :viewer_user_id
               AND NOT EXISTS (
                   SELECT 1 FROM friendships f
                   WHERE f.status = 'blocked'
                     AND f.user_low_id = LEAST(t.created_by_user_id, :viewer_user_id_low)
                     AND f.user_high_id = GREATEST(t.created_by_user_id, :viewer_user_id_high)
               )
               AND NOT EXISTS (
                   SELECT 1 FROM tournament_participants tp2 WHERE tp2.tournament_id = t.id AND tp2.user_id = :viewer_user_id_joined AND tp2.status != 'withdrawn'
               )
             ORDER BY t.created_at ASC"
        );
        $stmt->execute([
            'viewer_user_id' => $viewerUserId,
            'viewer_user_id_low' => $viewerUserId,
            'viewer_user_id_high' => $viewerUserId,
            'viewer_user_id_joined' => $viewerUserId,
        ]);

        return array_map($this->decode(...), $stmt->fetchAll());
    }

    public function markStarted(int $id): void
    {
        Connection::get()
            ->prepare("UPDATE tournaments SET status = 'in_progress', started_at = NOW() WHERE id = :id")
            ->execute(['id' => $id]);
    }

    /** Booster Draft's own pre-bracket phase (issue #91 follow-up) -- see TournamentService::startBoosterDraftPods()'s own docblock. */
    public function markDrafting(int $id): void
    {
        Connection::get()
            ->prepare("UPDATE tournaments SET status = 'drafting', started_at = NOW() WHERE id = :id")
            ->execute(['id' => $id]);
    }

    /** The 'drafting' -> 'in_progress' transition once every pod finishes -- started_at is already set from markDrafting(), so it's left alone here. */
    public function markInProgressAfterDrafting(int $id): void
    {
        Connection::get()
            ->prepare("UPDATE tournaments SET status = 'in_progress' WHERE id = :id")
            ->execute(['id' => $id]);
    }

    public function markCompleted(int $id, int $winnerUserId): void
    {
        Connection::get()
            ->prepare("UPDATE tournaments SET status = 'completed', winner_user_id = :winner, completed_at = NOW() WHERE id = :id")
            ->execute(['winner' => $winnerUserId, 'id' => $id]);
    }

    public function markCancelled(int $id): void
    {
        Connection::get()
            ->prepare("UPDATE tournaments SET status = 'cancelled', cancelled_at = NOW() WHERE id = :id")
            ->execute(['id' => $id]);
    }

    /**
     * Reported live: clean up tournaments a week after being cancelled/
     * completed, folded into the existing game/match cleanup cron -- see
     * bin/expire_and_delete_stale_games.php and
     * GameService::deleteStaleCompletedGames()'s own docblock for the
     * identical staleness idea applied to bare games. A still-'registration'/
     * 'in_progress'/'drafting' tournament is never touched here, however
     * old -- only a genuinely finished (one way or the other) one has
     * nothing left worth keeping around. `DELETE FROM tournaments`
     * cascades to every child table (tournament_participants/
     * tournament_rounds/tournament_matches/tournament_pods and its own
     * further children -- all ON DELETE CASCADE from tournaments, see
     * migrations 0334/0335/0337), so no manual per-table cleanup is
     * needed. The underlying `games`/`game_matches`/`draft_matches` rows
     * a tournament's own matches created are NOT touched here at all --
     * nothing in tournament_matches is an enforced foreign key back to
     * them (see migration 0334's own docblock), so this never orphans
     * anything there; they're cleaned up independently, on their own
     * per-game staleness clock, by deleteStaleCompletedGames() above.
     *
     * @return int how many tournaments were deleted
     */
    public function deleteStale(int $olderThanDays = 7): int
    {
        $stmt = Connection::get()->prepare(
            "DELETE FROM tournaments
             WHERE (status = 'completed' AND completed_at < (NOW() - INTERVAL :days_completed DAY))
                OR (status = 'cancelled' AND cancelled_at < (NOW() - INTERVAL :days_cancelled DAY))"
        );
        $stmt->execute(['days_completed' => $olderThanDays, 'days_cancelled' => $olderThanDays]);

        return $stmt->rowCount();
    }

    private function decode(array $row): array
    {
        $row['match_params'] = json_decode((string) $row['match_params'], true, 512, JSON_THROW_ON_ERROR);

        return $row;
    }
}
