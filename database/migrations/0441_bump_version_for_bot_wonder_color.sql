-- Bots now choose Wonder's color as the most common color among the moods
-- in play and the discard pile, instead of always taking the first color.
-- Pure code change; no schema change.
UPDATE schema_version SET version = '1.61.4' WHERE id = 1;
