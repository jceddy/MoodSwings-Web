-- Chaos Draft's own opt-in gate (issue #405 follow-up). Confirmed by the
-- maintainer: this app's card catalog is entirely fan-made rules text for
-- a real published TCG, and Chaos Draft's own 133-effect pool is
-- additional custom content layered on top of that -- there's a real
-- concern that an employee of the game's publisher could stumble onto it
-- (as an invited opponent, say) and get the wrong impression about what
-- this project is. A new personal preference, off by default so nobody
-- sees custom content unless they explicitly opt in -- the same
-- "default 0, must opt in" shape default_selections_mode_preference
-- already uses, as opposed to a pure-convenience preference like
-- auto_pass_on_empty_hand/auto_apply_scoring_bonuses that defaults to 1.
--
-- Deliberately named for the general category ("custom content"), not
-- "chaos_draft" specifically -- see "Custom card/effect formats" in
-- php-app/README.md for the full read/write route and exactly which
-- deck_type(s) this gates today (just chaos_draft, but framed to cover
-- any future fan-made-effects format without another migration).
ALTER TABLE users
    ADD COLUMN allow_custom_content TINYINT(1) NOT NULL DEFAULT 0 AFTER board_layout_preference;

UPDATE schema_version SET version = '1.28.40' WHERE id = 1;
