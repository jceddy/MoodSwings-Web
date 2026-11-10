-- Bots now use the discard-pile loot cards (Reminiscence, Remorse, Reverie, Warmth) (PHP only; no schema change).

UPDATE schema_version SET version = '1.64.43' WHERE id = 1;
