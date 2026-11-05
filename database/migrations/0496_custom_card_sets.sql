-- Custom card sets: community-designed sets that sit alongside the original Mood Swings
-- set (MSW) but only show up for players who opted into custom content
-- (users.allow_custom_content, Settings > "Allow custom card/effect formats").
--   * sets.is_custom flags such a set. A card belongs to a set through card_sets, so a
--     reprint of an original card is simply in both sets (and so is never hidden), while
--     a card that is only in custom sets is hidden from everyone who hasn't opted in.
--   * games.card_set_codes records which set(s) a game's random pools (Structure,
--     Power, jceddy's 75, One of Each, the draft pools, Sealed Deck, ...) are built from:
--     a comma-separated list of set codes. NULL means the original set only, which is
--     also what every existing game and every flow that never chooses (the Daily and
--     Weekly Sealed Pools, League starter decks, tournaments) keeps using.
--   * cards.art_set_code names the folder under web-static/img/cards/ a card's art lives in
--     ('MSW' for every original card, including a custom set's reprints of them).
--   * The first custom set is the community "Emotional Baggage" set (code XEMB, from the
--     jceddy/mood-swings-custom repository -- the name is still tentative there).
ALTER TABLE sets ADD COLUMN is_custom TINYINT(1) NOT NULL DEFAULT 0 AFTER name;

INSERT INTO sets (code, name, is_custom) VALUES ('XEMB', 'Emotional Baggage', 1);

ALTER TABLE games ADD COLUMN card_set_codes VARCHAR(100) NULL DEFAULT NULL;

ALTER TABLE cards ADD COLUMN art_set_code VARCHAR(10) NOT NULL DEFAULT 'MSW';

UPDATE schema_version SET version = '1.64.0' WHERE id = 1;
