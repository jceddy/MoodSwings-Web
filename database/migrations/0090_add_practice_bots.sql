-- Issue #140: basic AI practice bot(s).
--
-- is_bot marks a users row as a bot rather than a real account -- driven
-- entirely server-side (GameService/BotPlayerService), never logged into,
-- so it has no bearing on anything in the auth/session flow (see
-- AuthService). Placed after share_presence, the last column any prior
-- migration added to this table.
ALTER TABLE users
    ADD COLUMN is_bot TINYINT(1) NOT NULL DEFAULT 0 AFTER share_presence;

-- A small fixed roster of 3 distinct bot accounts -- the maximum number
-- of non-creator seats a 4-player game ever has (the human creator
-- always occupies one seat themselves), so up to 3 of these can be
-- seated in the same game at once with distinct, stable names. Each
-- gets a real (but unreachable, never sent to) email and a random
-- password hash purely to satisfy `users`' own NOT NULL/UNIQUE
-- constraints -- the hash's plaintext is thrown away immediately and is
-- never meant to be recoverable, since nothing ever logs in as a bot.
-- share_presence = 0 so a bot reads as 'hidden' (see PresenceService)
-- rather than a permanently-offline-looking friend; email_verified_at
-- is set anyway for consistency with every other row in this table,
-- even though AuthService::login() is never actually exercised for one.
INSERT INTO users (username, email, password_hash, share_presence, is_bot, email_verified_at) VALUES
    ('BotAlice', 'bot-alice@moodswings.invalid', '$2y$12$IqC4Ww5t3Um8/ayyLDpvUOGOotuhtOEOdHQ8qQv5r30bmQ.xnZcrS', 0, 1, NOW()),
    ('BotBen',   'bot-ben@moodswings.invalid',   '$2y$12$oz6uj/RV335QK0fbJHv3wuCTWmCZtOy5I/bEkGJnC7sRHMr5ULBG.', 0, 1, NOW()),
    ('BotCleo',  'bot-cleo@moodswings.invalid',  '$2y$12$mq/57jC7Ib0Ydsx/5n50Jel/YsCNxRJtJtAEOpZas6/Nyae.qo2t6', 0, 1, NOW());

UPDATE schema_version SET version = '1.11.20' WHERE id = 1;
