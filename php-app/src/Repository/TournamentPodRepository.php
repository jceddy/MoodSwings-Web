<?php

declare(strict_types=1);

namespace MoodSwings\Repository;

use MoodSwings\Database\Connection;

/**
 * Covers tournament_pods/tournament_pod_participants/
 * tournament_pod_boosters/tournament_pod_picks -- Booster Draft's own
 * pod-drafting phase (see BoosterDraftPodBuilder/BoosterPackBuilder for
 * the pure math this operates on, and TournamentService for the
 * orchestration) -- and, via tournament_pods.game_id, Grid Draft's own
 * "Pod draft (once)" option (see GridDraftPodBuilder), whose pods have
 * no booster/pick rows of their own at all, just a single backing Grid
 * Draft game (an ordinary `games`/`draft_matches` row, entirely
 * GameService's own to manage) -- and Grid Draft's third "Pods with
 * playoffs" option (grid_draft_pod_playoff), whose pods are formed and
 * drafted the exact same way but each go on to play their OWN bracket
 * (kind/winner_participant_id below) rather than feeding one bracket
 * shared across every pod.
 */
final class TournamentPodRepository
{
    /**
     * $gameId is Grid Draft pods' own backing game (see this class's own
     * docblock) -- null for a Booster Draft pod, which has no single
     * backing game. $kind is 'final' only for Grid Draft "Pods with
     * playoffs" (grid_draft_pod_playoff) own single winners' pod (see
     * TournamentService::startGridDraftPodPlayoffFinals()) -- 'regular'
     * (the default) for every other pod, including every
     * grid_draft_pod_playoff pod formed at tournament start.
     */
    public function createPod(int $tournamentId, int $podNumber, ?int $gameId = null, string $kind = 'regular'): int
    {
        $stmt = Connection::get()->prepare(
            'INSERT INTO tournament_pods (tournament_id, pod_number, game_id, kind) VALUES (:tournament_id, :pod_number, :game_id, :kind)'
        );
        $stmt->execute(['tournament_id' => $tournamentId, 'pod_number' => $podNumber, 'game_id' => $gameId, 'kind' => $kind]);

        return (int) Connection::get()->lastInsertId();
    }

    public function findPod(int $podId): ?array
    {
        $stmt = Connection::get()->prepare('SELECT * FROM tournament_pods WHERE id = :id');
        $stmt->execute(['id' => $podId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** The Grid Draft pod (see this class's own docblock) backed by $gameId, if any -- null for a game that isn't a tournament pod's own backing game at all. */
    public function findPodByGameId(int $gameId): ?array
    {
        $stmt = Connection::get()->prepare('SELECT * FROM tournament_pods WHERE game_id = :game_id');
        $stmt->execute(['game_id' => $gameId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** @return array[] every pod for this tournament, ordered by pod_number */
    public function listPodsForTournament(int $tournamentId): array
    {
        $stmt = Connection::get()->prepare(
            'SELECT * FROM tournament_pods WHERE tournament_id = :tournament_id ORDER BY pod_number ASC'
        );
        $stmt->execute(['tournament_id' => $tournamentId]);

        return $stmt->fetchAll();
    }

    public function advanceRound(int $podId, int $newRound): void
    {
        Connection::get()
            ->prepare('UPDATE tournament_pods SET current_round = :round WHERE id = :id')
            ->execute(['round' => $newRound, 'id' => $podId]);
    }

    public function markCompleted(int $podId): void
    {
        Connection::get()
            ->prepare("UPDATE tournament_pods SET status = 'completed', completed_at = NOW() WHERE id = :id")
            ->execute(['id' => $podId]);
    }

    /** Grid Draft "Pods with playoffs" only (issue #91 follow-up): drafting has finished and this pod's own bracket is now being played -- see TournamentService::startPodBracket(). */
    public function markPlaying(int $podId): void
    {
        Connection::get()
            ->prepare("UPDATE tournament_pods SET status = 'playing' WHERE id = :id")
            ->execute(['id' => $podId]);
    }

    /** Grid Draft "Pods with playoffs" only: this pod's own bracket has resolved -- see TournamentService::onPodBracketFinished(). */
    public function recordWinner(int $podId, int $winnerParticipantId): void
    {
        Connection::get()
            ->prepare("UPDATE tournament_pods SET status = 'completed', completed_at = NOW(), winner_participant_id = :winner WHERE id = :id")
            ->execute(['winner' => $winnerParticipantId, 'id' => $podId]);
    }

    public function addParticipant(int $podId, int $participantId, int $seatOrder): int
    {
        $stmt = Connection::get()->prepare(
            'INSERT INTO tournament_pod_participants (pod_id, participant_id, seat_order) VALUES (:pod_id, :participant_id, :seat_order)'
        );
        $stmt->execute(['pod_id' => $podId, 'participant_id' => $participantId, 'seat_order' => $seatOrder]);

        return (int) Connection::get()->lastInsertId();
    }

    public function findPodParticipant(int $podParticipantId): ?array
    {
        $stmt = Connection::get()->prepare('SELECT * FROM tournament_pod_participants WHERE id = :id');
        $stmt->execute(['id' => $podParticipantId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** The pod seat for a given tournament_participants.id, if they're seated in any pod at all. */
    public function findPodParticipantForParticipant(int $participantId): ?array
    {
        $stmt = Connection::get()->prepare('SELECT * FROM tournament_pod_participants WHERE participant_id = :participant_id');
        $stmt->execute(['participant_id' => $participantId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** @return array[] every seat in this pod, ordered by seat_order */
    public function listPodParticipants(int $podId): array
    {
        $stmt = Connection::get()->prepare(
            'SELECT * FROM tournament_pod_participants WHERE pod_id = :pod_id ORDER BY seat_order ASC'
        );
        $stmt->execute(['pod_id' => $podId]);

        return $stmt->fetchAll();
    }

    public function findPodParticipantBySeat(int $podId, int $seatOrder): ?array
    {
        $stmt = Connection::get()->prepare(
            'SELECT * FROM tournament_pod_participants WHERE pod_id = :pod_id AND seat_order = :seat_order'
        );
        $stmt->execute(['pod_id' => $podId, 'seat_order' => $seatOrder]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** @param int[] $cardIds exactly 15 card ids -- see BoosterPackBuilder */
    public function createBooster(int $podId, int $openerPodParticipantId, string $direction, array $cardIds): void
    {
        $stmt = Connection::get()->prepare(
            'INSERT INTO tournament_pod_boosters (pod_id, opener_pod_participant_id, direction, remaining_card_ids)
             VALUES (:pod_id, :opener_pod_participant_id, :direction, :remaining_card_ids)'
        );
        $stmt->execute([
            'pod_id' => $podId,
            'opener_pod_participant_id' => $openerPodParticipantId,
            'direction' => $direction,
            'remaining_card_ids' => json_encode($cardIds, JSON_THROW_ON_ERROR),
        ]);
    }

    /** @return ?array{id: int, pod_id: int, opener_pod_participant_id: int, direction: string, remaining_card_ids: int[]} */
    public function findBooster(int $openerPodParticipantId, string $direction): ?array
    {
        $stmt = Connection::get()->prepare(
            'SELECT * FROM tournament_pod_boosters WHERE opener_pod_participant_id = :opener AND direction = :direction'
        );
        $stmt->execute(['opener' => $openerPodParticipantId, 'direction' => $direction]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }

        $row['remaining_card_ids'] = json_decode((string) $row['remaining_card_ids'], true, 512, JSON_THROW_ON_ERROR);

        return $row;
    }

    /** @param int[] $remainingCardIds */
    public function updateBoosterRemainingCardIds(int $boosterId, array $remainingCardIds): void
    {
        Connection::get()
            ->prepare('UPDATE tournament_pod_boosters SET remaining_card_ids = :remaining_card_ids WHERE id = :id')
            ->execute(['remaining_card_ids' => json_encode($remainingCardIds, JSON_THROW_ON_ERROR), 'id' => $boosterId]);
    }

    public function recordPick(int $podParticipantId, int $round, string $direction, int $cardId): void
    {
        $stmt = Connection::get()->prepare(
            'INSERT INTO tournament_pod_picks (pod_participant_id, round, direction, card_id)
             VALUES (:pod_participant_id, :round, :direction, :card_id)'
        );
        $stmt->execute([
            'pod_participant_id' => $podParticipantId,
            'round' => $round,
            'direction' => $direction,
            'card_id' => $cardId,
        ]);
    }

    public function hasPicked(int $podParticipantId, int $round, string $direction): bool
    {
        $stmt = Connection::get()->prepare(
            'SELECT COUNT(*) FROM tournament_pod_picks WHERE pod_participant_id = :pod_participant_id AND round = :round AND direction = :direction'
        );
        $stmt->execute(['pod_participant_id' => $podParticipantId, 'round' => $round, 'direction' => $direction]);

        return (int) $stmt->fetchColumn() > 0;
    }

    /** How many of this pod's own seats' picks (both directions count separately) have landed for $round -- a pod of size P finishes the round once this reaches 2 * P. */
    public function countPicksForRound(int $podId, int $round): int
    {
        $stmt = Connection::get()->prepare(
            'SELECT COUNT(*) FROM tournament_pod_picks tpp
             JOIN tournament_pod_participants tppa ON tppa.id = tpp.pod_participant_id
             WHERE tppa.pod_id = :pod_id AND tpp.round = :round'
        );
        $stmt->execute(['pod_id' => $podId, 'round' => $round]);

        return (int) $stmt->fetchColumn();
    }

    /** @return int[] every card this seat has ever picked, oldest first -- exactly 30 once their pod finishes drafting */
    public function listPicksForParticipant(int $podParticipantId): array
    {
        $stmt = Connection::get()->prepare(
            'SELECT card_id FROM tournament_pod_picks WHERE pod_participant_id = :pod_participant_id ORDER BY id ASC'
        );
        $stmt->execute(['pod_participant_id' => $podParticipantId]);

        return array_map(intval(...), $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }
}
