-- Follow-up to issue #419's own Tactical Bot tier (migration 0236):
-- BOT_SEARCH_TIME_BUDGET_SECONDS = 150 turned out, in live testing, to be
-- too long to comfortably iterate against. This turns that ONE fixed
-- global budget into three selectable tiers, each its own bot account,
-- so a maintainer can pick a speed to test against at a glance in the
-- New Game dialog's own bot picker (colored there green/gold/red -- see
-- web-static/README.md's "New game dialog" section).
--
-- Per-bot column rather than a second global constant: a future tier (or
-- a maintainer-tuned budget for one specific bot) needs no further
-- schema change, just another row/UPDATE. NOT NULL DEFAULT matches
-- migration 0236's own BOT_SEARCH_TIME_BUDGET_SECONDS -- meaningless
-- (and never read) for a non-tactical bot the same way uses_tactical_ai
-- itself is meaningless for a non-bot user, so there's no need to make
-- it nullable just to express "doesn't apply here."
ALTER TABLE users
    ADD COLUMN tactical_ai_time_budget_seconds SMALLINT UNSIGNED NOT NULL DEFAULT 150 AFTER uses_tactical_ai;

-- BotSage keeps its identity as the existing "standard" (gold) tier, but
-- moves from the old 150s down into the new spread.
UPDATE users SET tactical_ai_time_budget_seconds = 60 WHERE username = 'BotSage';

-- Two more named accounts alongside it -- same "real but unreachable,
-- never sent to" email / thrown-away random password convention as
-- migrations 0090's/0236's own seeding.
INSERT INTO users (username, email, password_hash, share_presence, is_bot, uses_tactical_ai, tactical_ai_time_budget_seconds, email_verified_at) VALUES
    ('BotSageQuick', 'bot-sage-quick@moodswings.invalid', '$2y$12$T5JTH9b3j..GYCXpDlW/wOWdMfmH1S572KRBI3cpCcTklmpoQuPu6', 0, 1, 1, 30, NOW()),
    ('BotSageDeep',  'bot-sage-deep@moodswings.invalid',  '$2y$12$QUZuwv4iRXWf6Co02YzJXOKFx5eRA3pbTC7Zpyd2rEEpYR.na4g8e', 0, 1, 1, 90, NOW());

UPDATE schema_version SET version = '1.33.1' WHERE id = 1;
