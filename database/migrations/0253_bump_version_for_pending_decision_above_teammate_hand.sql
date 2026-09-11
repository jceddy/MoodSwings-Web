-- UI tweak, follow-up to migration 0252 (reported live: "let's move the
-- pending decisions panel above the teammate's hand panel, as well").
--
-- #pending-decision-panel moved to sit right before #teammate-hand-section
-- instead of right after it -- a pure DOM reorder (plain block flow, no
-- CSS order/flex/grid involved) with no JS changes.
--
-- No schema change, just the version bump MaintenanceGate needs to see
-- this deploy as caught up with the code.
UPDATE schema_version SET version = '1.33.17' WHERE id = 1;
