-- Saved user decklists (issue #92): lets a user save a decklist to their
-- account as a first-class, reusable object -- rather than only ever
-- supplying custom_deck_card_ids scoped to one games/game_players row via
-- the existing 'custom'/'custom_duel' deck_type flows (see "Custom
-- decklists"/"Custom decklists for Duel games" in php-app/README.md) --
-- with an optional flag sharing it with the owner's accepted friends
-- (see the `friendships` table, migration 0002). A prerequisite/
-- complement for a future deck builder (#93) and for persisting a
-- finished draft as a reusable deck via the new "Save deck" button next
-- to Quick/Winston/Grid Draft's own "Submit deck" button (see
-- submitDraftDeck()/`POST /games/draft/deck` in php-app/README.md).
--
-- card_ids mirrors games.custom_deck_card_ids's own JSON-array-of-
-- catalog-ids shape (one entry per copy, duplicates repeated). It's the
-- only column that's ever required. sideboard_card_ids is nullable JSON
-- of the same shape -- NULL for the common case (a deck saved via
-- paste/upload or picked from a dropdown has no sideboard concept at
-- all); only ever populated when saved from a completed draft's own
-- deck-building screen, from whichever drafted cards weren't included in
-- the submitted deck.
--
-- visibility is deliberately only 'private'/'friends' -- there is no
-- third "public to everyone" tier. This also doubles as the meaning of
-- the "public" checkbox on the draft format's own "Save deck" button:
-- that checkbox means friends-visible, never globally public.
CREATE TABLE IF NOT EXISTS user_decklists (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    name VARCHAR(120) NOT NULL,
    card_ids JSON NOT NULL,
    sideboard_card_ids JSON DEFAULT NULL,
    visibility ENUM('private', 'friends') NOT NULL DEFAULT 'private',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_user_decklists_user_id (user_id),
    CONSTRAINT fk_user_decklists_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE schema_version SET version = '0.12.0' WHERE id = 1;
