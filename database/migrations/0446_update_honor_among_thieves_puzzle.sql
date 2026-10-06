-- "Honor Among Thieves" update:
--   * The description no longer names the opponent's card in hand or the
--     card on top of the solver's deck -- that is information the solver
--     should get from the game itself.
--   * The deck is now a full 45-card random structure deck (23 commons, 14
--     uncommons, 6 rares, 2 mythics -- the Structure deck's own mix), still
--     with Courage on top. Only the top card is ever drawn in the winning
--     line, so the rest of the list is the same kind of filler as before.
UPDATE puzzles SET
    description = 'Each player has two round wins, and you played first this round. Your opponent has Compulsion, Benevolence and Honor in play (Honor chose you to go first). You have Hope and Charity in play and hold Dignity and Paranoia. You have two plays left. Win the game this round.',
    deck_card_ids = '[7,93,116,22,101,14,38,10,88,4,45,12,28,48,81,122,64,36,9,56,92,112,104,98,121,132,90,26,21,42,113,55,58,109,18,115,91,20,66,77,125,131,37,65,119]'
WHERE slug = 'honor-among-thieves';

UPDATE schema_version SET version = '1.61.9' WHERE id = 1;
