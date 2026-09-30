-- UI tweak: main page title/h1 rebranded from "MoodSwings-Web" to "MOOD"
-- with an "Obstructs Objective Directives" tagline underneath (recursive
-- acronym). Pure frontend change, no schema change.
UPDATE schema_version SET version = '1.58.2' WHERE id = 1;
