-- Squashes this branch's own migrations 0060-0066 (all still unmerged, so
-- immutability doesn't apply yet -- see database/README.md's "Adding a
-- new migration" section) into a single file, taking the schema from
-- 1.6.2 straight to 1.9.0. Covers, in order:
--
-- - GET /games/export (issue #99): raw per-game data dump for offline
--   archiving. No schema change.
-- - GET /games/past (issue #84 prep): a "Past games" lobby view,
--   excluding 'completed' games from GET /games except one still tied to
--   an undecided best-of-three draft match. No schema change.
-- - bin/expire_and_delete_stale_games.php (issue #84): a once-daily cron
--   deleting/force-ending games that have been stale for 7+ days.
--   game_events.event_type is a plain VARCHAR, so the new 'game_expired'
--   value needs no schema change either.
-- - 3-4 player Quick Draft (issue #189): draft_round_picks' old
--   kept_from_draw_ids/kept_from_received_ids/submitted_draw_at/
--   submitted_received_at columns (fixed at exactly 2 pick stages) are
--   replaced by a new draft_pile_stage_picks table, one row per
--   (draft_match_id, round_number, pile_owner_user_id, stage_number) --
--   see GameService::quickDraftPileSize()/submitQuickDraftPick()'s own
--   docblock for why a pile takes N stages, not a fixed 2, for N players.
-- - 3-4 player Grid Draft (issue #189): draft_grid_state's
--   first_pick_axis/first_pick_index columns (which only ever existed to
--   derive a single "is this the round's first or second pick" boolean)
--   are replaced by picks_this_round, a plain counter that generalizes to
--   any player count -- see GameService::submitGridDraftPick().
-- - jceddy's 150 Card deck: Quick Draft's and Grid Draft's own 4-player
--   'jceddys_75' pool sourcing swaps in a themed 150-card pool
--   (GameService::buildJceddys150DeckCardIds(), JCEDDYS_150_DECK_RARITY_SPEC)
--   instead of a literal doubling or a discard-reshuffle top-up. No
--   schema change.
-- - 3-4 player Winston Draft (issue #189): no schema change --
--   draft_winston_state.current_player_user_id and
--   last_draft_action_by_user_id were already fully N-player-ready by
--   original design (migrations 0035/0036); only GameService's own
--   turn-order arithmetic and pool-sizing constants needed to generalize.
ALTER TABLE draft_round_picks
    DROP COLUMN kept_from_draw_ids,
    DROP COLUMN kept_from_received_ids,
    DROP COLUMN submitted_draw_at,
    DROP COLUMN submitted_received_at;

-- One row per pile per stage once that stage's pick is made.
-- pile_owner_user_id identifies WHICH of the round's N piles this is (the
-- user it was originally dealt to via draft_round_picks) -- it does NOT
-- change as the pile passes hands. holder_user_id is whoever actually held
-- (and picked from) the pile at this stage -- equal to pile_owner_user_id
-- only at stage_number = 1, some other seated player for every later
-- stage. Both are needed: pile_owner_user_id to look up what pile a player
-- currently holds (and to derive discards once every stage exists),
-- holder_user_id to credit the right player's drafted_card_ids in
-- GameService::finalizeQuickDraft(). Passed cards (= the pile's contents
-- minus every stage's own kept_card_ids so far) and discarded cards (= the
-- final 2 cards left once every stage_number 1..N exists for a pile) are
-- both derived at read time rather than stored, exactly like the table
-- this replaces.
CREATE TABLE IF NOT EXISTS draft_pile_stage_picks (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    draft_match_id BIGINT UNSIGNED NOT NULL,
    round_number TINYINT UNSIGNED NOT NULL,
    pile_owner_user_id INT UNSIGNED NOT NULL,
    stage_number TINYINT UNSIGNED NOT NULL,
    holder_user_id INT UNSIGNED NOT NULL,
    kept_card_ids JSON NOT NULL,
    submitted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_draft_pile_stage_picks_per_stage (draft_match_id, round_number, pile_owner_user_id, stage_number),
    CONSTRAINT fk_draft_pile_stage_picks_match FOREIGN KEY (draft_match_id) REFERENCES draft_matches (id) ON DELETE CASCADE,
    CONSTRAINT fk_draft_pile_stage_picks_owner FOREIGN KEY (pile_owner_user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_draft_pile_stage_picks_holder FOREIGN KEY (holder_user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- picks_this_round (how many picks have been made in the CURRENT round so
-- far, reset to 0 at the start of every round) replaces first_pick_axis/
-- first_pick_index and generalizes cleanly: 0 means "nobody's picked yet
-- this round" (matching the old both-null state), and
-- GameService::submitGridDraftPick() refills the cells a pick just
-- cleared only while picks_this_round is still at most (player_count - 2)
-- -- i.e. every pick except the round's last two, which for a 2-player
-- match is every pick (0 <= 0), so 2-player Grid Draft's own long-standing
-- "second pick takes whatever's left, nothing ever refills mid-round"
-- behavior is completely unchanged.
ALTER TABLE draft_grid_state
    DROP COLUMN first_pick_axis,
    DROP COLUMN first_pick_index,
    ADD COLUMN picks_this_round TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER first_picker_user_id;

UPDATE schema_version SET version = '1.9.0' WHERE id = 1;
