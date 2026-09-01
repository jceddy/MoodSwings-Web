-- Version-only bump covering a handful of small bug fixes: bot Conviction
-- targeting, the "Draft, sealed_deck deck" label, the loading overlay
-- letting page content show through, Hostility's own second stage not
-- offering itself as a target, Anger's combined-value field rejecting a
-- Superiority that only fits the budget once Anger is in play, themed
-- card art re-requesting a known-missing file on every board poll, and
-- buttons not picking up the active theme's own colors. None of these
-- touch the schema, so no other statement is needed here -- just the
-- patch bump per CLAUDE.md's own versioning convention.
UPDATE schema_version SET version = '1.30.1' WHERE id = 1;
