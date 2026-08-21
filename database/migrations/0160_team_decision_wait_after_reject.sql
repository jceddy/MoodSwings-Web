-- Open/Closed Team Play (issue #360): once a human confirmer rejected a
-- practice bot's own turn_order/draw_recipient proposal,
-- advanceBotTeamDecision() had no way to tell that had just happened --
-- confirmTeamDecision()'s own reject branch clears proposer_game_player_id/
-- proposed_game_player_id back to NULL, so the very next automated drive
-- (the same request, or the next GET /games/state poll) saw an ordinary
-- fresh 'propose' phase and had the bot immediately re-propose the exact
-- same arbitrary candidate (chooseTeamDecisionProposal() is deliberately
-- non-strategic -- always candidateGamePlayerIds[0]) all over again,
-- making a human's own reject a no-op whenever a bot is on the deciding
-- team.
--
-- rejected_game_player_id remembers whichever candidate a human just
-- rejected for THIS still-open decision row (each decision is its own
-- fresh INSERT -- see createTeamDecision() -- so this naturally starts
-- unset for every new decision and never needs resetting mid-row: once a
-- human is confirmed to be involved, every later proposal in this same
-- row comes from a human, who can no longer reject their own team's
-- confirming bot, since a bot always approves). advanceBotTeamDecision()
-- now defers entirely to a human once this is set, rather than immediately
-- overriding the human's own rejection with the same suggestion again.
ALTER TABLE game_team_decisions
    ADD COLUMN rejected_game_player_id INT UNSIGNED DEFAULT NULL AFTER proposed_game_player_id,
    ADD CONSTRAINT fk_team_decisions_rejected FOREIGN KEY (rejected_game_player_id) REFERENCES game_players (id) ON DELETE RESTRICT;

UPDATE schema_version SET version = '1.28.16' WHERE id = 1;
