-- Issue #417's own "Move the whole Round / Score / Players section under
-- my hand" item. Confirmed by the maintainer: rather than moving the
-- section unconditionally, this is a new personal preference (Settings
-- dialog's own "Display" section, alongside the card-size slider) with
-- two values -- 'above_play_area' (the section's current position,
-- between the top banners and the draft/in-play area -- DEFAULT, so
-- every existing/new player's board renders exactly as it always has
-- unless they explicitly opt in) and 'below_hand' (the section instead
-- renders after "Your hand" AND after the "selected card to play" panel
-- (#choices-panel), per the maintainer's own follow-up clarification).
-- See "Board layout preference" in web-static/README.md for exactly
-- which elements move and how (a DOM relocation in game.js, not a CSS
-- reorder) and php-app/README.md for this column's own read/write route.
ALTER TABLE users
    ADD COLUMN board_layout_preference ENUM('above_play_area', 'below_hand') NOT NULL DEFAULT 'above_play_area' AFTER auto_apply_scoring_bonuses;

UPDATE schema_version SET version = '1.28.30' WHERE id = 1;
