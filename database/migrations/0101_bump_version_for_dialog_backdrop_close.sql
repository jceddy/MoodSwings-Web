-- No schema change: every <dialog> across the site (web-static/game/index.html's
-- dozen-plus dialogs, plus the shared #resources-dialog in every page's own
-- footer) now closes when its own backdrop is clicked, the same as Escape
-- or a Close button already did -- a new interaction, not a bug fix, so
-- this is a MINOR bump. Client-side only: closeDialogOnBackdropClick() in
-- web-static/js/app.js (shared, called from both app.js's own
-- #resources-dialog init and game.js's existing dialog-wiring loop). See
-- the "Backdrop click" note in web-static/README.md.
UPDATE schema_version SET version = '1.17.0' WHERE id = 1;
