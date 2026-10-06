-- "Honor Among Thieves": the description no longer names the opponent's
-- card in hand or the card on top of the solver's deck -- that is
-- information the solver should get from the game itself.
UPDATE puzzles SET description = 'Each player has two round wins, and you played first this round. Your opponent has Compulsion, Benevolence and Honor in play (Honor chose you to go first). You have Hope and Charity in play and hold Dignity and Paranoia. You have two plays left. Win the game this round.'
WHERE slug = 'honor-among-thieves';

UPDATE schema_version SET version = '1.61.9' WHERE id = 1;
