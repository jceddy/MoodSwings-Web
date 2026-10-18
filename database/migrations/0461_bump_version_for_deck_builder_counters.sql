-- Web client: the draft/sealed deck builder now shows a live "cards selected" counter (red under the minimum) and,
-- for the daily/weekly sealed pools, rare/mythic counters against their caps (each red once over its cap). No
-- schema change.

UPDATE schema_version SET version = '1.62.10' WHERE id = 1;
