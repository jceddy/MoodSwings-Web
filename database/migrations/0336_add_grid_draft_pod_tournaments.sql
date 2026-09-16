-- Grid Draft tournaments (issue #91 follow-up) may now pick either
-- draft type Grid Draft already supported ("Fresh draft each match",
-- unchanged -- every bracket match is its own independent 2-player Grid
-- Draft) or a new "Pod draft (once)" option, deck_type
-- 'grid_draft_pod': participants split into pods of <=4 (Grid Draft's
-- own existing multiplayer cap -- a 3x3 grid for 2-3 players, 4x4 for
-- exactly 4, see GameService::gridDraftRounds()), each pod plays one
-- ordinary Grid Draft game among its own members (ALL of Grid Draft's
-- existing drafting/deck-building machinery, entirely unchanged) to
-- build a personal pool, then the tournament's real bracket/Swiss mixes
-- players across pods freely -- exactly Booster Draft's own structure,
-- just backed by Grid Draft's drafting mechanic instead of booster
-- packs, and with a pod cap of 4 instead of 8.
--
-- tournament_pods gains game_id: the pod's own backing Grid Draft
-- game -- NULL for a Booster Draft tournament's own pods (which have no
-- single backing game; their own drafting state lives entirely in
-- tournament_pod_boosters/tournament_pod_picks instead). Once every
-- seat in that game has submitted a deck (GameService::submitDraftDeck()'s
-- own "did everyone just finish" check, reported to TournamentService
-- via the new TournamentMatchObserver::onDraftDeckSubmitted() hook),
-- each pod participant's own final drafted_card_ids is copied into
-- tournament_participants.draft_pool_card_ids (the same column Booster
-- Draft already uses) and the game itself is abandoned
-- (GameService::abandonDraftGame()) -- it only ever exists to run the
-- shared drafting UI, never to actually be played out as a real match,
-- since the real matches are ordinary 'custom_duel' games restricted to
-- each side's own drafted pool exactly like Booster Draft's own bracket
-- matches already are (see TournamentService::startMatchGame()'s own
-- docblock).
ALTER TABLE tournament_pods
    ADD COLUMN game_id INT UNSIGNED DEFAULT NULL AFTER tournament_id,
    ADD CONSTRAINT fk_tournament_pods_game FOREIGN KEY (game_id) REFERENCES games (id) ON DELETE SET NULL;

UPDATE schema_version SET version = '1.49.0' WHERE id = 1;
