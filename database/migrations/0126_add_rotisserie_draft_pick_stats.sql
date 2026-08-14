-- Rotisserie Draft's own pick-position signal (issue #361, following
-- issue #315's own per-draft-format convention -- see migration 0070 and
-- CardStatsService's own docblock): a sum/count column pair recording an
-- average "how early was this card taken" for Rotisserie Draft picks,
-- alongside the existing quick_draft/winston_draft/grid_draft pairs.
--
-- The "position" here is the draft's own 1-based global pick_index
-- (draft_rotisserie_state.pick_index + 1 at the moment of the pick) --
-- the most literal draft-position ordinal of any of the four formats,
-- since Rotisserie Draft deals its whole pool face-up once and players
-- simply take turns in a fixed snake order, with no per-round/pile/grid
-- structure to derive a proxy from the way the other three formats do.
ALTER TABLE card_stats
    ADD COLUMN rotisserie_draft_pick_position_sum BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER grid_draft_pick_round_count,
    ADD COLUMN rotisserie_draft_pick_position_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER rotisserie_draft_pick_position_sum;

UPDATE schema_version SET version = '1.25.1' WHERE id = 1;
