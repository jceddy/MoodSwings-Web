-- Synchronous mode's own 60-second draft-pick timer (increment 3 -- see
-- migration 0329's own docblock for the roadmap this follows, and
-- migration 0330 for increment 2's analogous per-action timer on
-- ordinary games). Extends synchronous mode to the 'draft' format (all
-- 5 draft-family deck_types, plus Sealed Deck/Sealed Pool of the
-- Day/Weekly Sealed Pool, which have no live picks of their own but
-- still get the same ready-check-gates-everything treatment) -- see
-- GameService::SYNCHRONOUS_MODE_ALLOWED_FORMATS.
--
-- pending_draft_init: non-NULL from creation until every seat has
-- clicked Ready, for a synchronous draft match only -- createGame()
-- itself no longer deals a synchronous draft's first round/pile/pool
-- the way it always has for an async one (GameService::markReady() does
-- that instead, once both seats are in, via
-- initializeDeferredSynchronousDraft()). Holds whatever
-- deck_type-specific config that later call still needs
-- (rotisserie_draft's own cutoff count, tiered_rotisserie_draft's own
-- tier pools/mode) that isn't already recoverable from
-- draft_matches.pool_card_ids/draft_match_players alone. Always NULL
-- for a non-synchronous match (nothing ever writes it), so
-- draftHasBeenInitialized()'s own "has this actually started" check is
-- unconditionally true for every match this feature predates.
--
-- pick_deadline_at: the current pick's own 60-second deadline, reset by
-- GameService::resetSynchronousDraftPickDeadlineIfNeeded() after every
-- submitted pick (and once more when drafting itself begins), enforced
-- on every GET /games/state poll by enforceSynchronousDraftPickDeadline()
-- -- the exact same real-time, poll-driven shape as games.action_deadline_at
-- (migration 0330), just scoped to the WHOLE match rather than one
-- `games` row, since a draft match's own drafting phase happens before
-- any of its up to 3 `games` rows ever reaches 'in_progress'. NULL
-- whenever nobody is currently waiting on a pick (not yet initialized,
-- not synchronous, or drafting has already finished).
ALTER TABLE draft_matches
    ADD COLUMN pending_draft_init JSON DEFAULT NULL AFTER pool_card_ids,
    ADD COLUMN pick_deadline_at TIMESTAMP NULL DEFAULT NULL AFTER current_round;

UPDATE schema_version SET version = '1.45.0' WHERE id = 1;
