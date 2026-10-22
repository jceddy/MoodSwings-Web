-- "Hand-Me-Down" and "Return to Sender" updates: the descriptions read "3x
-- Generosity" / "2x Generosity" instead of spelling the card out once per copy.
-- No change to either puzzle itself.
UPDATE puzzles SET
    description = 'Each player has two round wins, and your opponent played first this round. Your opponent has 3x Generosity in play and holds Bashfulness. You have Vulnerability, Complacency and Indifference in play and hold Thrill, Harmony and Suspicion, and you have one play. Win the game this round.'
WHERE slug = 'hand-me-down';

UPDATE puzzles SET
    description = 'Each player has two round wins, and your opponent played first this round. Your opponent has 2x Generosity, Thrill and Contempt in play. You have Validation and Recklessness in play and hold Denial, and you have one play. Win the game this round.'
WHERE slug = 'return-to-sender';

UPDATE schema_version SET version = '1.62.19' WHERE id = 1;
