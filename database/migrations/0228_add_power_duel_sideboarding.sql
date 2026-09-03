-- Sideboarding for Power Duel best-of-three matches: a 'custom_duel'
-- game built under the existing "Power Duel" duel_deck_rules preset
-- (custom_duel_rules_preset = 'power') can now optionally declare a
-- sideboard of up to 5 cards alongside its 15-card main deck, and swap
-- freely between the two across a best-of-three match's own games --
-- the traditional TCG sideboarding rule, rather than issue #90's
-- existing "freely resubmit anything" story every other custom_duel
-- preset still uses. Opted into per match at createGame() time
-- (allow_sideboarding); off by default, so every other format/preset
-- combination is entirely unaffected -- see GameService::createGame()/
-- submitCustomDuelDeck()/advanceGameMatch().
ALTER TABLE game_matches
    ADD COLUMN allow_sideboarding TINYINT(1) NOT NULL DEFAULT 0 AFTER format;

-- A seated player's own currently-benched sideboard cards for a
-- 'custom_duel' game -- null for every other deck_type, and null here
-- too whenever sideboarding isn't in play for this match (see above).
ALTER TABLE game_players
    ADD COLUMN custom_deck_sideboard_card_ids JSON DEFAULT NULL AFTER custom_deck_card_ids;
