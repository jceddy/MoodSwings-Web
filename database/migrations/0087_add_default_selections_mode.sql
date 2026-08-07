-- "Default selections" mode (issue #274): a per-game toggle, chosen once
-- at game creation like format/deck_type (GameService::createGame()),
-- applying for that game's whole lifetime to every seated player -- not
-- a personal preference, and not a client-side/localStorage toggle. When
-- on, the choices panel (and any pending-decision response panel --
-- both share the same field shape and rendering code, see
-- CardChoiceSchema.php's own docblock) pre-fills each field with a
-- reasonable default the player can still change before submitting,
-- instead of leaving it blank/unselected. Defaults to 0 (off) so every
-- existing/new game's behavior is unchanged unless explicitly chosen at
-- creation, same reasoning migration 0051's disable_cooldown toggle
-- documents for its own default-off column.
ALTER TABLE games
    ADD COLUMN default_selections_mode TINYINT(1) NOT NULL DEFAULT 0 AFTER wins_needed;

UPDATE schema_version SET version = '1.11.17' WHERE id = 1;
