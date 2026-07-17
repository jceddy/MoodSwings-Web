-- Winston Draft: last_take_pile_by_user_id only recorded a *take*, so a
-- player who instead declined all 3 piles and took the mandatory
-- top-of-deck draw left the opponent's view showing a stale (possibly
-- many-turns-old) pile number instead of reflecting what actually just
-- happened. Widen the column to record either outcome per user_id: an
-- integer pile number (1-3) for a take, or the string "deck" for a
-- decline-all-piles auto-draw. Renamed to match the widened meaning.
ALTER TABLE draft_winston_state
    CHANGE COLUMN last_take_pile_by_user_id last_draft_action_by_user_id JSON NULL;

UPDATE schema_version SET version = '0.11.0' WHERE id = 1;
