<?php

declare(strict_types=1);

namespace MoodSwings\Repository;

use MoodSwings\Database\Connection;

/**
 * Covers both tournament_rounds and tournament_matches -- a round is
 * just the grouping/status container for the matches created alongside
 * it (see TournamentBracketBuilder), so callers virtually always need
 * both together.
 */
final class TournamentMatchRepository
{
    public function createRound(int $tournamentId, string $bracket, int $roundNumber): int
    {
        $stmt = Connection::get()->prepare(
            'INSERT INTO tournament_rounds (tournament_id, bracket, round_number) VALUES (:tournament_id, :bracket, :round_number)'
        );
        $stmt->execute(['tournament_id' => $tournamentId, 'bracket' => $bracket, 'round_number' => $roundNumber]);

        return (int) Connection::get()->lastInsertId();
    }

    public function findRoundById(int $roundId): ?array
    {
        $stmt = Connection::get()->prepare('SELECT * FROM tournament_rounds WHERE id = :id');
        $stmt->execute(['id' => $roundId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function findRound(int $tournamentId, string $bracket, int $roundNumber): ?array
    {
        $stmt = Connection::get()->prepare(
            'SELECT * FROM tournament_rounds WHERE tournament_id = :tournament_id AND bracket = :bracket AND round_number = :round_number'
        );
        $stmt->execute(['tournament_id' => $tournamentId, 'bracket' => $bracket, 'round_number' => $roundNumber]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function listRounds(int $tournamentId): array
    {
        $stmt = Connection::get()->prepare(
            'SELECT * FROM tournament_rounds WHERE tournament_id = :tournament_id ORDER BY bracket ASC, round_number ASC'
        );
        $stmt->execute(['tournament_id' => $tournamentId]);

        return $stmt->fetchAll();
    }

    public function markRoundStatus(int $roundId, string $status): void
    {
        Connection::get()
            ->prepare('UPDATE tournament_rounds SET status = :status WHERE id = :id')
            ->execute(['status' => $status, 'id' => $roundId]);
    }

    /** True once every match in the round has resolved (completed or bye). */
    public function isRoundComplete(int $roundId): bool
    {
        $stmt = Connection::get()->prepare(
            "SELECT COUNT(*) FROM tournament_matches WHERE round_id = :round_id AND status NOT IN ('completed', 'bye')"
        );
        $stmt->execute(['round_id' => $roundId]);

        return (int) $stmt->fetchColumn() === 0;
    }

    public function createMatch(
        int $tournamentId,
        int $roundId,
        int $slot,
        ?int $participant1Id,
        ?int $participant2Id,
        string $status,
    ): int {
        $stmt = Connection::get()->prepare(
            'INSERT INTO tournament_matches (tournament_id, round_id, slot, participant1_id, participant2_id, status)
             VALUES (:tournament_id, :round_id, :slot, :participant1_id, :participant2_id, :status)'
        );
        $stmt->execute([
            'tournament_id' => $tournamentId,
            'round_id' => $roundId,
            'slot' => $slot,
            'participant1_id' => $participant1Id,
            'participant2_id' => $participant2Id,
            'status' => $status,
        ]);

        return (int) Connection::get()->lastInsertId();
    }

    public function find(int $id): ?array
    {
        $stmt = Connection::get()->prepare('SELECT * FROM tournament_matches WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function findByGameId(int $gameId): ?array
    {
        $stmt = Connection::get()->prepare('SELECT * FROM tournament_matches WHERE game_id = :game_id');
        $stmt->execute(['game_id' => $gameId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function findByGameMatchId(int $gameMatchId): ?array
    {
        $stmt = Connection::get()->prepare('SELECT * FROM tournament_matches WHERE game_match_id = :game_match_id');
        $stmt->execute(['game_match_id' => $gameMatchId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function findByDraftMatchId(int $draftMatchId): ?array
    {
        $stmt = Connection::get()->prepare('SELECT * FROM tournament_matches WHERE draft_match_id = :draft_match_id');
        $stmt->execute(['draft_match_id' => $draftMatchId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function listForRound(int $roundId): array
    {
        $stmt = Connection::get()->prepare('SELECT * FROM tournament_matches WHERE round_id = :round_id ORDER BY slot ASC');
        $stmt->execute(['round_id' => $roundId]);

        return $stmt->fetchAll();
    }

    public function listForTournament(int $tournamentId): array
    {
        $stmt = Connection::get()->prepare('SELECT * FROM tournament_matches WHERE tournament_id = :tournament_id ORDER BY round_id ASC, slot ASC');
        $stmt->execute(['tournament_id' => $tournamentId]);

        return $stmt->fetchAll();
    }

    public function setAdvanceTargets(
        int $matchId,
        ?int $winnerAdvancesToMatchId,
        ?int $winnerAdvancesToSlot,
        ?int $loserAdvancesToMatchId,
        ?int $loserAdvancesToSlot,
    ): void {
        $stmt = Connection::get()->prepare(
            'UPDATE tournament_matches
             SET winner_advances_to_match_id = :winner_match, winner_advances_to_slot = :winner_slot,
                 loser_advances_to_match_id = :loser_match, loser_advances_to_slot = :loser_slot
             WHERE id = :id'
        );
        $stmt->execute([
            'winner_match' => $winnerAdvancesToMatchId,
            'winner_slot' => $winnerAdvancesToSlot,
            'loser_match' => $loserAdvancesToMatchId,
            'loser_slot' => $loserAdvancesToSlot,
            'id' => $matchId,
        ]);
    }

    /** Fills whichever of participant1_id/participant2_id is still empty for $slot (1 or 2). */
    public function fillSlot(int $matchId, int $slot, int $participantId): void
    {
        $column = $slot === 1 ? 'participant1_id' : 'participant2_id';
        Connection::get()
            ->prepare("UPDATE tournament_matches SET {$column} = :participant_id WHERE id = :id")
            ->execute(['participant_id' => $participantId, 'id' => $matchId]);
    }

    public function markGameCreated(int $matchId, int $gameId, ?int $gameMatchId, ?int $draftMatchId): void
    {
        $stmt = Connection::get()->prepare(
            "UPDATE tournament_matches
             SET game_id = :game_id, game_match_id = :game_match_id, draft_match_id = :draft_match_id, status = 'in_progress'
             WHERE id = :id"
        );
        $stmt->execute([
            'game_id' => $gameId,
            'game_match_id' => $gameMatchId,
            'draft_match_id' => $draftMatchId,
            'id' => $matchId,
        ]);
    }

    public function markBye(int $matchId, int $winnerParticipantId): void
    {
        Connection::get()
            ->prepare("UPDATE tournament_matches SET status = 'bye', winner_participant_id = :winner, completed_at = NOW() WHERE id = :id")
            ->execute(['winner' => $winnerParticipantId, 'id' => $matchId]);
    }

    public function markCompleted(int $matchId, int $winnerParticipantId): void
    {
        Connection::get()
            ->prepare("UPDATE tournament_matches SET status = 'completed', winner_participant_id = :winner, completed_at = NOW() WHERE id = :id")
            ->execute(['winner' => $winnerParticipantId, 'id' => $matchId]);
    }
}
