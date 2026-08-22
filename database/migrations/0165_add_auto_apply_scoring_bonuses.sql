-- Issue #397: "auto-apply Enthusiasm/Passion's scoring bonus" -- a
-- personal preference, surfaced in the Settings dialog's own "Game
-- defaults" section alongside default selections mode (migration 0093)
-- and auto-pass on empty hand (migration 0096). Enthusiasm's own
-- per-round "take the bonus?" decision and Passion's own "which opponent
-- mood?" decision are both genuinely optional, but the obviously-correct
-- answer (always take Enthusiasm's bonus; always take Passion's own
-- highest-value opponent mood) can only ever increase the player's own
-- score, so requiring a manual response every single round either card
-- stays in play is pure friction -- see GameService::advanceAutomatedTurns()
-- for the server-side auto-apply check this drives, and
-- sneakinessPlayedThisRound() for the one case (Sneakiness swapping
-- scores this round) where maximizing your own score isn't necessarily
-- correct, so auto-apply correctly falls back to asking manually instead.
-- Defaults to 1 (on), the same reasoning auto_pass_on_empty_hand already
-- used: a pure convenience with no real behavior change to opt into,
-- since the auto-applied answer is always at least as good as any other
-- choice whenever it fires at all.
ALTER TABLE users
    ADD COLUMN auto_apply_scoring_bonuses TINYINT(1) NOT NULL DEFAULT 1 AFTER auto_pass_on_empty_hand;

UPDATE schema_version SET version = '1.28.21' WHERE id = 1;
