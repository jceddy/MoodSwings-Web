-- Winston Draft: track each player's own most recent pile *take* (not
-- pass), so the opponent's last-drafted pile number can be shown without
-- ever revealing what was actually in it. Turns strictly alternate and
-- either player can pass any number of times before eventually taking,
-- so a single "the last take, whoever it was" column isn't enough --
-- from either player's own perspective, "the opponent's last take" means
-- that OTHER player's own most recent take specifically, which can be
-- several turns back. Keyed by user_id (JSON map, e.g. {"5": 2}) rather
-- than two fixed columns, since a draft match has no fixed "player
-- 1"/"player 2" slot the way a seat_order does elsewhere in this schema.
ALTER TABLE draft_winston_state
    ADD COLUMN last_take_pile_by_user_id JSON NULL AFTER current_pile_number;

UPDATE schema_version SET version = '0.10.0' WHERE id = 1;
