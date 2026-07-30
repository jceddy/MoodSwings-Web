-- Issue #189: 3-4 player Grid Draft (Quick Draft's own multiplayer
-- support landed in migration 0063; Winston Draft's is still undecided
-- and stays 2-player-only). createGame() widens its 2-player gate for
-- 'draft' + 'grid_draft' the same way it already does for 'quick_draft'
-- -- no schema change needed for that part, games/game_players are
-- already player-count-agnostic.
--
-- draft_grid_state itself needs one change: first_pick_axis/
-- first_pick_index only ever existed to derive a single boolean ("is
-- this the round's first pick or its second") -- they were never read
-- for the actual row/column-overlap math, which is derived purely from
-- grid_card_ids' own null/non-null cells. For N players a round has N
-- sequential picks, not a fixed 2, so that boolean isn't enough --
-- picks_this_round (how many picks have been made in the CURRENT round
-- so far, reset to 0 at the start of every round) replaces both columns
-- and generalizes cleanly: 0 means "nobody's picked yet this round"
-- (matching the old both-null state), and GameService::submitGridDraftPick()
-- refills the cells a pick just cleared only while picks_this_round is
-- still at most (player_count - 2) -- i.e. every pick except the round's
-- last two, which for a 2-player match is every pick (0 <= 0), so
-- 2-player Grid Draft's own long-standing "second pick takes whatever's
-- left, nothing ever refills mid-round" behavior is completely
-- unchanged.
ALTER TABLE draft_grid_state
    DROP COLUMN first_pick_axis,
    DROP COLUMN first_pick_index,
    ADD COLUMN picks_this_round TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER first_picker_user_id;

UPDATE schema_version SET version = '1.8.0' WHERE id = 1;
