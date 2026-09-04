-- Issue #90 follow-up: Power Duel sideboarding -- a best-of-three Duel
-- match built under custom_duel's existing "Power Duel" preset can now
-- opt into swapping cards between a 15-card main deck and a declared
-- up-to-5-card sideboard across the match's games, the traditional TCG
-- sideboarding rule, in place of that preset's own default "deck is
-- locked for the whole match" behavior. The card-by-card Deck Builder
-- also gained its own sideboard panel for Free-form/Power Duel. A new
-- full feature of its own (its own README section), so this bumps the
-- minor version per CLAUDE.md's own versioning convention.
UPDATE schema_version SET version = '1.32.0' WHERE id = 1;
