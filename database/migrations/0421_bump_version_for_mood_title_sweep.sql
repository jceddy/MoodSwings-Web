-- UI tweak: swept the remaining "MoodSwings-Web" page titles/headings
-- (game board, maintenance page, every other page's <title> suffix, the
-- backend-rendered maintenance/email-verification titles, and the push
-- notification default) to "MOOD", matching the login page's rebrand in
-- 1.58.2. Pure frontend/backend text change, no schema change.
UPDATE schema_version SET version = '1.58.3' WHERE id = 1;
