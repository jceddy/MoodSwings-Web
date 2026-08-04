-- No schema change: removes the "(issue #315)" reference from the Stats
-- page's user-facing intro paragraph (web-static/stats/index.html). This
-- migration exists purely to keep schema_version in sync with the VERSION
-- bump, the same way 0024/.../0070 already did for their own schema-less
-- changes -- MaintenanceGate compares the deployed VERSION file against
-- this table on every request, so a VERSION bump with no matching
-- schema_version update would show maintenance mode after deploy even
-- though nothing about the schema actually changed.
UPDATE schema_version SET version = '1.11.1' WHERE id = 1;
