-- No schema change: adds GET /games/draft-pool (GameService::draftMatchPoolView())
-- and the frontend's "View draft pool" dialog for a completed Quick/Winston/
-- Grid Draft match, sectioned by drafter plus an undrafted-cards section
-- (issue #314). This migration exists purely to keep schema_version in
-- sync with the VERSION bump, the same way 0024/.../0075 already did for
-- their own schema-less changes -- MaintenanceGate compares the deployed
-- VERSION file against this table on every request, so a VERSION bump
-- with no matching schema_version update would show maintenance mode
-- after deploy even though nothing about the schema actually changed.
UPDATE schema_version SET version = '1.11.6' WHERE id = 1;
