-- Reported live: give Completionist ("Unlock every other achievement")
-- its own tier above Platinum, since Platinum is already the top tier
-- several ordinary (non-meta) rows use (Legend, Draft Completionist,
-- etc.) -- the single achievement that requires unlocking every other
-- one deserves to stand out as its own, higher tier rather than sharing
-- a badge color with those.
ALTER TABLE achievements
    MODIFY COLUMN tier ENUM('Bronze', 'Silver', 'Gold', 'Platinum', 'Diamond') NOT NULL;

UPDATE achievements SET tier = 'Diamond' WHERE slug = 'completionist';

UPDATE schema_version SET version = '1.51.7' WHERE id = 1;
