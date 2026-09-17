-- Power Duel tournaments: reported live, "the deck submission should
-- happen when the player joins the tournament -- players use the same
-- submitted deck for the entire tournament," rather than the previous
-- per-match story (submit a fresh decklist for every single match's own
-- game 1, resetting every round). A participant's own submitted deck,
-- validated once against the tournament's 'power' duel_deck_rules preset
-- at join time (createTournament()'s own auto-join for the creator,
-- joinOpenTournament()/acceptInvite() for everyone else) -- null for
-- every non-'custom_duel' tournament, and null here too until actually
-- submitted. Editable (TournamentService::submitTournamentDeck()) while
-- the tournament is still 'registration'; locked once startTournament()
-- seeds the bracket, the same way an ordinary custom_duel game's own
-- deck locks once submitted for a "locked" (non-sideboarding) match.
--
-- deck_sideboard_card_ids is only ever non-null when the tournament
-- itself opted into sideboarding (tournaments.match_params.allow_sideboarding) --
-- declared once here, at join time, rather than at "game 1 of match 1"
-- the way an ordinary ad hoc Power Duel match's own sideboarding pool
-- is declared; every match's own game 2/3 swap validation
-- (GameService::validateAndStorePowerDuelSideboardSwap()) still reads
-- its pool from that match's own game 1 game_players row exactly as
-- before -- TournamentService::startMatchGame() now just seeds that row
-- directly from here instead of leaving it for a fresh per-match
-- submission.
ALTER TABLE tournament_participants
    ADD COLUMN deck_name VARCHAR(120) DEFAULT NULL AFTER current_deck_card_ids,
    ADD COLUMN deck_card_ids JSON DEFAULT NULL AFTER deck_name,
    ADD COLUMN deck_sideboard_card_ids JSON DEFAULT NULL AFTER deck_card_ids;
