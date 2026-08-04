-- Card/draft statistics (issue #315): a new server-wide aggregate table,
-- incremented incrementally as games complete and draft picks are made
-- (mirroring user_lifetime_stats' own lazy-create ON DUPLICATE KEY UPDATE
-- convention -- see GameService::bumpLifetimeStats()) rather than
-- computed lazily from historical game data, since completed games are
-- permanently deleted after 7 days (deleteStaleCompletedGames()) and
-- Winston/Winston Draft's own pick state is deleted the instant each
-- draft finishes -- there would be nothing left to read from by the time
-- anyone looked at a stats page.
--
-- Keyed by catalog_card_id (cards.id), never a per-game instance id --
-- see GameService::serializeCard()'s own catalog_card_id field and
-- BoardState::catalogCardId() for the same distinction elsewhere in this
-- codebase.
--
-- times_in_deck/times_in_won_deck: how many completed games' decks a
-- card ended up in, and how many of those were won -- see
-- CardStatsService::recordGameCompletion().
-- times_played/times_played_in_won_game: same shape, but for cards
-- actually played (game_events.event_type = 'mood_played') rather than
-- merely present in the deck.
-- *_pick_position_sum/*_pick_position_count (one pair per draft format):
-- running totals for an average "how early was this card taken" signal,
-- written live at pick-submission time (each format's own notion of
-- "position" differs -- see CardStatsService's own docblock) -- never
-- compared across formats, so each format gets its own column pair
-- rather than one shared value.
CREATE TABLE IF NOT EXISTS card_stats (
    catalog_card_id SMALLINT UNSIGNED NOT NULL,
    times_in_deck INT UNSIGNED NOT NULL DEFAULT 0,
    times_in_won_deck INT UNSIGNED NOT NULL DEFAULT 0,
    times_played INT UNSIGNED NOT NULL DEFAULT 0,
    times_played_in_won_game INT UNSIGNED NOT NULL DEFAULT 0,
    quick_draft_pick_position_sum BIGINT UNSIGNED NOT NULL DEFAULT 0,
    quick_draft_pick_position_count INT UNSIGNED NOT NULL DEFAULT 0,
    winston_draft_pick_pile_size_sum BIGINT UNSIGNED NOT NULL DEFAULT 0,
    winston_draft_pick_pile_size_count INT UNSIGNED NOT NULL DEFAULT 0,
    grid_draft_pick_round_sum BIGINT UNSIGNED NOT NULL DEFAULT 0,
    grid_draft_pick_round_count INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (catalog_card_id),
    CONSTRAINT fk_card_stats_catalog_card_id FOREIGN KEY (catalog_card_id) REFERENCES cards (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE schema_version SET version = '1.11.0' WHERE id = 1;
