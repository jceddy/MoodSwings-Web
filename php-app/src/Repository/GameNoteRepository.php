<?php

declare(strict_types=1);

namespace MoodSwings\Repository;

use MoodSwings\Database\Connection;

final class GameNoteRepository
{
    /**
     * Issue #463: once a game belongs to a draft match ($draftMatchId
     * non-null), this seat's note is looked up by (draft_match_id,
     * $userId) instead of $gamePlayerId -- one shared note per player for
     * the whole match, surviving a rematch's fresh game_player_id the
     * same way chat now does. A non-match game keeps the original
     * per-game_player_id lookup unchanged.
     *
     * @return ?string the note's raw text, or null if this seat/match has
     *         never saved one yet
     */
    public function findFor(int $gamePlayerId, ?int $draftMatchId, int $userId): ?string
    {
        $stmt = $draftMatchId !== null
            ? Connection::get()->prepare('SELECT note_text FROM game_notes WHERE draft_match_id = :match_id AND user_id = :user_id')
            : Connection::get()->prepare('SELECT note_text FROM game_notes WHERE game_player_id = :game_player_id');
        $stmt->execute($draftMatchId !== null
            ? ['match_id' => $draftMatchId, 'user_id' => $userId]
            : ['game_player_id' => $gamePlayerId]);
        $noteText = $stmt->fetchColumn();

        return $noteText === false ? null : $noteText;
    }

    /**
     * Creates this seat's/match's own note row on first save, overwrites
     * its text on every one after. $gamePlayerId is always recorded
     * (informational once $draftMatchId is set -- see this table's own
     * migration docblock for why (draft_match_id, user_id) is the
     * actually-unique key there, not game_player_id), so it's simply
     * whichever game in the match the player happened to be editing from
     * most recently.
     */
    public function upsert(int $gamePlayerId, ?int $draftMatchId, int $userId, string $noteText): void
    {
        $stmt = $draftMatchId !== null
            ? Connection::get()->prepare(
                'INSERT INTO game_notes (game_player_id, draft_match_id, user_id, note_text) VALUES (:game_player_id, :match_id, :user_id, :note_text)
                 ON DUPLICATE KEY UPDATE note_text = VALUES(note_text), game_player_id = VALUES(game_player_id)'
            )
            : Connection::get()->prepare(
                'INSERT INTO game_notes (game_player_id, user_id, note_text) VALUES (:game_player_id, :user_id, :note_text)
                 ON DUPLICATE KEY UPDATE note_text = VALUES(note_text)'
            );
        $stmt->execute($draftMatchId !== null
            ? ['game_player_id' => $gamePlayerId, 'match_id' => $draftMatchId, 'user_id' => $userId, 'note_text' => $noteText]
            : ['game_player_id' => $gamePlayerId, 'user_id' => $userId, 'note_text' => $noteText]);
    }
}
