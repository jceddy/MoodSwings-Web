-- A fifth deck_type, 'custom': the creator supplies their own decklist
-- (uploaded as a text file or pasted into a form field) instead of one of
-- the algorithmically-assembled pools. Only supported for Traditional
-- (non-'duel') games -- GameService::createGame() rejects the combination
-- server-side.
--
-- custom_deck_name is the decklist's own optional "Name" metadata field
-- (see DecklistParser); NULL when the decklist didn't specify one, in
-- which case the client shows "Uploaded Deck" instead. custom_deck_card_ids
-- is the fully-resolved list of catalog card ids (one entry per copy,
-- already expanded -- e.g. "3 Charity" contributes three 3's), parsed and
-- validated once at createGame() time rather than re-parsed at
-- startGame() time, so a decklist error surfaces immediately rather than
-- only once the game is started.
ALTER TABLE games
    MODIFY COLUMN deck_type ENUM('structure', 'power', 'jceddys_75', 'custom', 'one_of_each') NOT NULL DEFAULT 'structure',
    ADD COLUMN custom_deck_name VARCHAR(120) DEFAULT NULL AFTER deck_type,
    ADD COLUMN custom_deck_card_ids JSON DEFAULT NULL AFTER custom_deck_name;
