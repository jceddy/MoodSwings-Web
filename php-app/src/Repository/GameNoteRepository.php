<?php

declare(strict_types=1);

namespace MoodSwings\Repository;

use MoodSwings\Database\Connection;

final class GameNoteRepository
{
    /**
     * @return ?string the note's raw text, or null if this seat has never
     *         saved one yet
     */
    public function findByGamePlayerId(int $gamePlayerId): ?string
    {
        $stmt = Connection::get()->prepare('SELECT note_text FROM game_notes WHERE game_player_id = :game_player_id');
        $stmt->execute(['game_player_id' => $gamePlayerId]);
        $noteText = $stmt->fetchColumn();

        return $noteText === false ? null : $noteText;
    }

    /**
     * Creates this seat's own note row on first save, overwrites its text
     * on every one after -- one row per game_player_id, never more.
     */
    public function upsert(int $gamePlayerId, string $noteText): void
    {
        $stmt = Connection::get()->prepare(
            'INSERT INTO game_notes (game_player_id, note_text) VALUES (:game_player_id, :note_text)
             ON DUPLICATE KEY UPDATE note_text = VALUES(note_text)'
        );
        $stmt->execute(['game_player_id' => $gamePlayerId, 'note_text' => $noteText]);
    }
}
