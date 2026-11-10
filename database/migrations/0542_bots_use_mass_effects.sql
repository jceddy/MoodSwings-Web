-- Bots now use the mass-effect cards (Rage, Wrath, Chagrin, Reckoning, Hesitation, Disorientation, Repentance) (PHP only; no schema change).

UPDATE schema_version SET version = '1.64.46' WHERE id = 1;
