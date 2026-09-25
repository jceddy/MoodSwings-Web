-- Issue #192: same-turn infinite-combo detection (e.g. Thrill<->Fear,
-- Fear+Angst, Thrill+Angst+Nostalgia -- see BoardState::turnStateSignature()'s
-- own docblock). Tracks how many times each distinct board-state signature
-- (every hand + every mood in play + the discard pile) has occurred so far
-- this turn, so a human player can be warned on the 3rd occurrence and have
-- their turn auto-passed on the 4th, and a bot can be steered away from the
-- 3rd occurrence instead of wasting its turn on it.
--
-- Same shape/lifecycle as the existing pending_play_grants column --
-- NULL/absent decodes as "nothing seen yet this turn" (a brand new turn or
-- an older row from before this existed), and GameService::updateRoundTurnState()
-- resets it to fresh alongside pending_play_grants the moment the turn
-- changes hands.
ALTER TABLE game_rounds
    ADD COLUMN loop_state_signature_counts JSON DEFAULT NULL AFTER pending_play_grants;

UPDATE schema_version SET version = '1.53.0' WHERE id = 1;
