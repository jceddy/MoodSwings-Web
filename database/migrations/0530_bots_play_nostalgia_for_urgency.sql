-- Bots now play Nostalgia with an empty discard pile when its extra play turns Urgency on and decides the round (PHP only; no schema change).

UPDATE schema_version SET version = '1.64.34' WHERE id = 1;
