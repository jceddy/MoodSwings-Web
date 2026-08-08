-- "Auto-pass on empty hand" -- a personal preference, surfaced in the
-- Settings dialog's own "Game defaults" section alongside default
-- selections mode (migration 0093). When your hand is empty and it's
-- your turn to act, there is genuinely nothing else you could legally
-- do besides pass (playing a card always requires one from hand) --
-- see GameService::advanceAutomatedTurns() for the server-side check
-- this drives, an extension of the same turn-advancing loop practice
-- bots (issue #140) already use. Defaults to 1 (on) -- unlike
-- default_selections_mode_preference's own default-off, this is a
-- pure convenience with no behavior change to actually opt into (an
-- empty hand always meant "pass" anyway; this just saves the click),
-- so every existing/new user starts with it already saving them time.
ALTER TABLE users
    ADD COLUMN auto_pass_on_empty_hand TINYINT(1) NOT NULL DEFAULT 1 AFTER default_selections_mode_preference;

UPDATE schema_version SET version = '1.14.0' WHERE id = 1;
