-- Quick Draft's per-game sideboard step (GameService::submitQuickDraftDeck())
-- always starts a new game's deck_card_ids as NULL (see
-- advanceQuickDraftMatch()) so startGame() can't silently treat an
-- unconfirmed leftover deck as already submitted. That left the frontend's
-- deck-trim picker with nothing better to default its checkboxes to than
-- "every drafted card", forcing the player to redo their whole trim from
-- scratch before every single game in the match instead of just adjusting
-- it. previous_deck_card_ids records whatever deck_card_ids was right
-- before it got nulled for the next game, purely so the frontend can
-- pre-select it as a starting point -- it plays no part in startGame()'s
-- own "has this game's deck been submitted yet" gate, which still keys
-- off deck_card_ids alone.
ALTER TABLE draft_match_players ADD COLUMN previous_deck_card_ids JSON DEFAULT NULL AFTER deck_card_ids;

UPDATE schema_version SET version = '0.6.1' WHERE id = 1;
