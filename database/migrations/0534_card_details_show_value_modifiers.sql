-- The card detail view now shows which parity Orderliness was played with and lists "+N from <card>" on moods whose value a static modifier changes (PHP + web UI only; no schema change).

UPDATE schema_version SET version = '1.64.38' WHERE id = 1;
