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

        return $row === false ? null : $this->decode($row);
    }

    public function findForUser(int $tournamentId, int $userId): ?array
    {
        $stmt = Connection::get()->prepare(
            'SELECT * FROM tournament_participants WHERE tournament_id = :tournament_id AND user_id = :user_id'
        );
        $stmt->execute(['tournament_id' => $tournamentId, 'user_id' => $userId]);
        $row = $stmt->fetch();

        return $row === false ? null : $this->decode($row);
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

        return array_map($this->decode(...), $stmt->fetchAll());
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

    /**
     * Booster Draft (issue #91 follow-up): a participant's own 30-card
     * drafted pool, set once their pod finishes drafting -- see
     * TournamentPodRepository's own docblock.
     *
     * @param int[] $cardIds
     */
    public function setDraftPoolCardIds(int $id, array $cardIds): void
    {
        Connection::get()
            ->prepare('UPDATE tournament_participants SET draft_pool_card_ids = :card_ids WHERE id = :id')
            ->execute(['card_ids' => json_encode($cardIds, JSON_THROW_ON_ERROR), 'id' => $id]);
    }

    /**
     * Booster Draft's own persistent deck -- overwritten every time this
     * participant submits a new one, for every tournament match they
     * play (see GameService::submitCustomDuelDeck()'s own
     * onCustomDuelDeckSubmitted() hook) -- null until their first.
     *
     * @param int[] $cardIds
     */
    public function setCurrentDeckCardIds(int $id, array $cardIds): void
    {
        Connection::get()
            ->prepare('UPDATE tournament_participants SET current_deck_card_ids = :card_ids WHERE id = :id')
            ->execute(['card_ids' => json_encode($cardIds, JSON_THROW_ON_ERROR), 'id' => $id]);
    }

    /**
     * Power Duel's own join-time deck (issue reported live: "the deck
     * submission should happen when the player joins the tournament --
     * players use the same submitted deck for the entire tournament") --
     * see TournamentService::submitTournamentDeck()'s own docblock for
     * validation. $sideboardCardIds is only ever non-null when this
     * tournament opted into sideboarding; $name mirrors game_players'
     * own custom_deck_name, purely for display.
     *
     * @param int[] $cardIds
     * @param int[]|null $sideboardCardIds
     */
    public function setDeck(int $id, ?string $name, array $cardIds, ?array $sideboardCardIds): void
    {
        Connection::get()->prepare(
            'UPDATE tournament_participants SET deck_name = :name, deck_card_ids = :card_ids, deck_sideboard_card_ids = :sideboard_card_ids WHERE id = :id'
        )->execute([
            'name' => $name,
            'card_ids' => json_encode($cardIds, JSON_THROW_ON_ERROR),
            'sideboard_card_ids' => $sideboardCardIds !== null ? json_encode($sideboardCardIds, JSON_THROW_ON_ERROR) : null,
            'id' => $id,
        ]);
    }

    /** draft_pool_card_ids/current_deck_card_ids/deck_card_ids/deck_sideboard_card_ids are all null until their own respective flow sets them. */
    private function decode(array $row): array
    {
        $row['draft_pool_card_ids'] = $row['draft_pool_card_ids'] !== null
            ? json_decode((string) $row['draft_pool_card_ids'], true, 512, JSON_THROW_ON_ERROR)
            : null;
        $row['current_deck_card_ids'] = $row['current_deck_card_ids'] !== null
            ? json_decode((string) $row['current_deck_card_ids'], true, 512, JSON_THROW_ON_ERROR)
            : null;
        $row['deck_card_ids'] = $row['deck_card_ids'] !== null
            ? json_decode((string) $row['deck_card_ids'], true, 512, JSON_THROW_ON_ERROR)
            : null;
        $row['deck_sideboard_card_ids'] = $row['deck_sideboard_card_ids'] !== null
            ? json_decode((string) $row['deck_sideboard_card_ids'], true, 512, JSON_THROW_ON_ERROR)
            : null;

        return $row;
    }
}
