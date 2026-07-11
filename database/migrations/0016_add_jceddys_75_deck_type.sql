-- Adds a fourth deck_type: "jceddy's 75 Card" -- per color, 1 random
-- Mythic, 2 different random Rares, 4 random Uncommons (up to 2 copies of
-- any single Uncommon), and 8 random Commons (up to 3 copies of any single
-- Common) -- 15 cards per color, 75 total across White/Blue/Black/Red/
-- Green. Purely additive (no existing rows reference a value being
-- renamed), so this only needs to widen the enum, unlike 0014's
-- widen/migrate/narrow for an actual rename.
ALTER TABLE games
    MODIFY COLUMN deck_type ENUM('structure', 'power', 'jceddys_75', 'one_of_each') NOT NULL DEFAULT 'structure';
