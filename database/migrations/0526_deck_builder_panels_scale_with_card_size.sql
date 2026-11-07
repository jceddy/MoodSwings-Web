-- Deck builder: the catalog/deck panels' minimum width now scales with the card-size preference, so large cards fill whole rows instead of two across (CSS only; version bump only).

UPDATE schema_version SET version = '1.64.30' WHERE id = 1;
