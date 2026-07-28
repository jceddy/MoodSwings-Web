-- In-game notepad for private player notes (issue #258): a small
-- freeform scratchpad tied to one specific seat (game_players.id) in one
-- specific game -- never shared with anyone else at the table. Keyed
-- directly on game_player_id, the same way every other per-seat concept
-- already is (resigned_at, custom_deck_name, the initial card pass),
-- rather than a separate (user_id, game_id) compound -- a seat already
-- uniquely identifies "this player, in this game." Lazily created on
-- first save (no row exists until then) -- see
-- GameNoteRepository::upsert(). Editable only while the game is still
-- 'in_progress' (see GameService::saveNote()); once it reaches a
-- terminal status ('completed'/'abandoned') the note stays fully
-- visible but read-only, matching every other in-progress-only board
-- action.
CREATE TABLE IF NOT EXISTS game_notes (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    game_player_id INT UNSIGNED NOT NULL,
    note_text MEDIUMTEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_game_notes_game_player_id (game_player_id),
    CONSTRAINT fk_game_notes_game_player_id FOREIGN KEY (game_player_id) REFERENCES game_players (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE schema_version SET version = '1.5.0' WHERE id = 1;
