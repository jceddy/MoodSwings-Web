-- Hostile Takeover: don't spell out the opponent's hand in the description
-- (the player works that out), and drop the hint's first sentence, keeping
-- only the one about Rationalization's hand swap and the play conditions.
UPDATE puzzles SET
    description = 'Score 13 points this turn. Your hand: Rationalization, Validation, Charity and Friendliness.',
    hint = 'Rationalization''s hand swap only costs you nothing once it is the last card in your hand, and each extra play your cards grant comes with conditions: read every one.'
WHERE slug = 'hostile-takeover';

UPDATE schema_version SET version = '1.60.1' WHERE id = 1;
