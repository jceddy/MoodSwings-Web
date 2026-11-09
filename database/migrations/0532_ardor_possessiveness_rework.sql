-- Community set 'Emotional Baggage' (XEMB) rework of two cards:
--   Ardor: each of its owner's red moods is increased by the number of OTHER red moods they have (it no longer counts itself).
--   Possessiveness: worth 6 with three or more cards in the discard pile (was five).
-- Art for both was also refreshed.

UPDATE cards
SET rules_text = 'While in play — Each of your red moods has its value increased by the number of other red moods you have.'
WHERE id = 218 AND name = 'Ardor';

UPDATE cards
SET rules_text = 'While in play — This mood''s value is 6 if there are three or more cards in the discard pile.'
WHERE id = 248 AND name = 'Possessiveness';

UPDATE schema_version SET version = '1.64.36' WHERE id = 1;
