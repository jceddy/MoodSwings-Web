-- Closed Team Play's own round-1 "Pass 2 cards to your teammate" panel
-- (#initial-card-pass-panel) was left out of the shared "boxed panel"
-- rule #choices-panel/#pending-decision-panel/#team-decision-panel
-- already use (border + padding + margin: 1rem 0), so it rendered with no
-- border and no space at all below it -- its own "Pass cards" button sat
-- flush against the "Pass" turn button directly underneath it. Added it
-- to that same shared rule. Pure frontend (style.css) -- no schema
-- change. See the `#initial-card-pass-panel` paragraph in
-- web-static/README.md.
UPDATE schema_version SET version = '1.21.1' WHERE id = 1;
