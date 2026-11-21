-- Community set 'Emotional Baggage' (XEMB) tweaks to four cards (new card images):
--   Reverie: putting the card on the bottom of the deck now also draws a card AND grants an additional mood this turn (the
--     "after playing" half only; the "while in play" reaction still just puts a card on the bottom and draws).
--   Neglect: triggers for each opponent with ONE OR FEWER cards in hand (was none).
--   Thrift: the additional play from the discard pile needs a 0, 1, or 2 in its top right corner (was 0-3).
--   Adrenaline: worth 2 (was 0).

UPDATE cards
SET rules_text = 'After playing this mood — You may put a card from the discard pile on the bottom of the deck. If you do, draw a card and you may play an additional mood this turn. While in play — Each time you play another mood, you may put a card from the discard pile on the bottom of the deck. If you do, draw a card.'
WHERE id = 190 AND name = 'Reverie';

UPDATE cards
SET rules_text = 'While in play — Before scoring, each opponent with one or fewer cards in their hand puts one of their highest value moods into the discard pile.'
WHERE id = 208 AND name = 'Neglect';

UPDATE cards
SET rules_text = 'After playing this mood — You may play an additional mood this turn from the discard pile if it has a 0, 1, or 2 in its top right corner.'
WHERE id = 253 AND name = 'Thrift';

UPDATE cards
SET base_value = 2
WHERE id = 217 AND name = 'Adrenaline';

UPDATE schema_version SET version = '1.64.75' WHERE id = 1;
