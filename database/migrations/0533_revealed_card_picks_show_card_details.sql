-- Revealed-card picks (Nosiness, Greed, Entitlement, Inspiration, Prudence) now show each candidate as a card thumbnail that opens its details (PHP + web UI only; no schema change).

UPDATE schema_version SET version = '1.64.37' WHERE id = 1;
