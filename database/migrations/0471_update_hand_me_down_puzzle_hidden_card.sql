-- "Hand-Me-Down" update: the description no longer names the card in the
-- opponent's hand -- the solver shouldn't be told what it is ("holds one card",
-- the same wording "Forced Hand" uses). Nothing about the puzzle itself changes.
UPDATE puzzles SET
    description = 'Each player has two round wins, and your opponent played first this round. Your opponent has 3x Generosity in play and holds one card. You have Vulnerability, Complacency and Indifference in play and hold Thrill, Harmony and Suspicion, and you have one play. Win the game this round.'
WHERE slug = 'hand-me-down';

UPDATE schema_version SET version = '1.62.20' WHERE id = 1;
