-- A sixth deck_type, 'custom_duel': for Duel games only, each of the two
-- players supplies their own decklist (same file/paste format as the
-- 'custom' deck_type -- see DecklistParser) rather than sharing one deck
-- or drawing from an algorithmically-assembled pool. Unlike 'custom',
-- nothing is parsed at createGame() time -- the creator instead defines
-- the deck-building RULES both players' own decklists must satisfy (see
-- DuelDeckRules), and each player submits their own decklist afterward,
-- while the game is still 'waiting', via GameService::submitCustomDuelDeck().
--
-- custom_duel_rules_preset records which preset (if any) the creator
-- picked -- 'structure'/'power'/'jceddys_75' lock the rule values to
-- match those deck types' own generators (see DuelDeckRules::forPreset()),
-- 'user_defined' means the creator picked the three rule values
-- themselves. Only meaningful when deck_type = 'custom_duel'.
--
-- custom_duel_min_cards/rarity_limits/duplicate_limits are the resolved
-- rule values themselves (already resolved from the preset, if one was
-- used, at createGame() time) -- rarity_limits and duplicate_limits are
-- both `{rarity: count}` maps that may omit any rarity, meaning "no
-- restriction for that rarity" (see DuelDeckRules).
ALTER TABLE games
    MODIFY COLUMN deck_type ENUM('structure', 'power', 'jceddys_75', 'custom', 'custom_duel', 'one_of_each') NOT NULL DEFAULT 'structure',
    ADD COLUMN custom_duel_rules_preset ENUM('structure', 'power', 'jceddys_75', 'user_defined') DEFAULT NULL AFTER custom_deck_card_ids,
    ADD COLUMN custom_duel_min_cards SMALLINT UNSIGNED DEFAULT NULL AFTER custom_duel_rules_preset,
    ADD COLUMN custom_duel_rarity_limits JSON DEFAULT NULL AFTER custom_duel_min_cards,
    ADD COLUMN custom_duel_duplicate_limits JSON DEFAULT NULL AFTER custom_duel_rarity_limits;

-- Each duel player's own submitted decklist, once GameService::
-- submitCustomDuelDeck() validates it against the game's own
-- custom_duel_* rules above -- mirrors games.custom_deck_name/
-- custom_deck_card_ids, just scoped to one seat instead of the whole
-- table. NULL until that player submits; startGame() refuses to deal for
-- a 'custom_duel' game until every seat's custom_deck_card_ids is set.
ALTER TABLE game_players
    ADD COLUMN custom_deck_name VARCHAR(120) DEFAULT NULL AFTER team_id,
    ADD COLUMN custom_deck_card_ids JSON DEFAULT NULL AFTER custom_deck_name;
