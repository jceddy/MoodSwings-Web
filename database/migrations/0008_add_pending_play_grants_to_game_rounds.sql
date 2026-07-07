-- Adds storage for *restricted* extra-play grants: some cards (Benevolence,
-- Friendliness, Kindness, Eagerness) grant an additional play only usable
-- on a mood meeting some condition, rather than an unconditional extra
-- play like Charity's. plays_remaining's plain count can't express that on
-- its own -- this stores the actual list of outstanding grants (one JSON
-- object per grant, or null for an unconditional one), whose length is
-- kept in sync with plays_remaining by whoever writes it. See
-- BoardState::$playGrants / grantAllows() in the rules engine.
ALTER TABLE game_rounds
    ADD COLUMN pending_play_grants JSON DEFAULT NULL AFTER plays_remaining;
