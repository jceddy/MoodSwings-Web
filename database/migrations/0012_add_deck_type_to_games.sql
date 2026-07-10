-- Until now every game used the same 133-card pool -- one copy of every
-- printed card, assembled by GameService::startGame() as range(1, 133).
-- deck_type introduces a second option: 'standard' (a randomly-assembled
-- 45-card singleton deck matching a new box's own printed rarity
-- distribution -- 23 common/14 uncommon/6 rare/2 mythic) alongside the
-- existing pool, renamed here 'one_of_each' for clarity now that it's one
-- of two choices rather than the only one. 'standard' is the default,
-- matching how a new physical box is actually played out of the box --
-- 'one_of_each' is the opt-in for the fuller, higher-variance card pool.
-- Chosen once at createGame() time (like format) and read by startGame()
-- when the deck is actually assembled; nothing about which cards a
-- particular game ends up with is decided until then, matching how the
-- format/wins_needed choices already work.
ALTER TABLE games
    ADD COLUMN deck_type ENUM('standard', 'one_of_each') NOT NULL DEFAULT 'standard' AFTER format;
