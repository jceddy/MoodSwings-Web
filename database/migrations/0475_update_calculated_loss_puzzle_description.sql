-- "Calculated Loss" update: the description no longer says the opponent holds
-- Intimidation -- the solver can work that out from what is public (the opponent's
-- hand size, and every card already played or discarded), so it says "one card",
-- the same wording "Forced Hand" and "Hand-Me-Down" use. Nothing about the puzzle
-- itself changes.
UPDATE puzzles SET
    description = 'You have two round wins and your opponent has one. Your opponent played first this round: they have Glee (which they played this round), Duplicity, Bashfulness, Boredom and Apathy in play, and hold one card. You have Celebration and Apathy in play and hold Insecurity, Ambition, Patience and Triumph. Your deck is empty. Win the game.'
WHERE slug = 'calculated-loss';

UPDATE schema_version SET version = '1.62.24' WHERE id = 1;
