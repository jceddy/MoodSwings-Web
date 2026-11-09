-- Bots now use Scorn's "while in play" suppression when an opponent's mood shares the played card's color (PHP only; no schema change).

UPDATE schema_version SET version = '1.64.40' WHERE id = 1;
