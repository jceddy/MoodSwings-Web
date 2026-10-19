-- "Cut Your Losses" update:
--   * The description no longer names the card on top of the solver's deck --
--     that is information the solver should get from the game itself (the same
--     change 0446 made to "Honor Among Thieves").
--   * The deck is now a full 45-card filler list, still with Disregard on top.
--     Only the top card is ever drawn in the winning line (Hate draws exactly
--     one). Joy is left out of the filler so extra_play_source_card_id can only
--     ever find the Joy in play.
UPDATE puzzles SET
    description = 'Each player has two round wins, and your opponent played first this round. Your opponent has Discipline, Frustration, Complacency and Boredom in play. You have Joy and Pity in play and hold Hate and Apathy. You have two plays. Win the game this round.',
    deck_card_ids = '[113,7,93,116,22,101,14,38,10,88,4,45,12,28,48,81,122,64,36,9,56,92,112,104,98,121,132,90,26,21,42,55,58,109,18,115,91,20,66,77,126,131,37,65,119]'
WHERE slug = 'cut-your-losses';

UPDATE schema_version SET version = '1.62.13' WHERE id = 1;
