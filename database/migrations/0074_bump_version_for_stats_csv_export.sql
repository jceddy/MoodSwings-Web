-- No schema change: renames the Stats page's "Download JSON" button to
-- "JSON" and adds a "CSV" export button beside it
-- (web-static/js/stats.js, web-static/stats/index.html). This migration
-- exists purely to keep schema_version in sync with the VERSION bump,
-- the same way 0024/.../0073 already did for their own schema-less
-- changes -- MaintenanceGate compares the deployed VERSION file against
-- this table on every request, so a VERSION bump with no matching
-- schema_version update would show maintenance mode after deploy even
-- though nothing about the schema actually changed.
UPDATE schema_version SET version = '1.11.4' WHERE id = 1;
