-- Shakedown's description no longer spells out the opponent's hand or how
-- the opponent picks which card to reveal -- working those out is part of
-- the puzzle -- nor who wins a tie. The tie rule moves into the hint,
-- appended to the existing hint text. No schema change.
UPDATE puzzles SET
    description = 'Each player has two round wins, and your opponent played first. Your hand: Duplicity and Intimidation. Your opponent has Benevolence (2 points) in play. Win the game this turn.',
    hint = 'Intimidation only ever takes the card your opponent values least -- and a card you''re handed is yours to keep instead of playing. Ties go to the player who went first.'
WHERE slug = 'shakedown';

UPDATE schema_version SET version = '1.58.5' WHERE id = 1;
