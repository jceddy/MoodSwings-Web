-- Backs MaintenanceGate.php's runtime check: the deployed VERSION file is
-- compared against this table's single row on every request (except
-- /health and /verify-email -- see php-app/public/index.php), and any
-- mismatch, or this table being missing entirely (e.g. right after this
-- very migration is deployed but not yet applied), shows a maintenance
-- page instead of running the app against a possibly-mismatched schema.
--
-- Every future schema-changing migration must end with an UPDATE to this
-- table matching the same PR's VERSION bump -- see "Adding a new
-- migration" in database/README.md for why it must be the LAST statement
-- in the file (DDL isn't transactional, so ordering it last is what keeps
-- a partially-applied migration correctly still showing maintenance mode
-- rather than falsely clearing it).
--
-- A fixed singleton primary key (rather than relying on "only one row will
-- ever exist") turns an accidental INSERT instead of UPDATE -- an easy
-- mistake when hand-pasting into phpMyAdmin -- into a visible duplicate-key
-- error instead of silent, non-deterministic SELECT ... LIMIT 1 behavior.
CREATE TABLE schema_version (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY DEFAULT 1,
    version VARCHAR(20) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO schema_version (id, version) VALUES (1, '0.2.0');
