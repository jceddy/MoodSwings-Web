-- Discord notifications (issue #232), first pass: account linking only --
-- actually sending a notification over Discord, and the Interactions
-- Endpoint's own signature-verified request handling, are pure
-- application code with no schema of their own, so this migration is
-- just the two tables account-linking needs.
--
-- Linking uses Discord's own OAuth2 "Connect" flow (identify scope only
-- -- this never needs a Discord bot to share a server with the player,
-- since the Application is registered as a "User Install" in the
-- Developer Portal) rather than a typed pairing code, so there's no
-- Discord-side access/refresh token worth retaining here: once
-- discord_user_id is known, every actual notification is sent with the
-- Application's own bot token against the REST API, never the player's
-- OAuth token.
CREATE TABLE IF NOT EXISTS discord_accounts (
    user_id INT UNSIGNED NOT NULL,
    discord_user_id VARCHAR(32) NOT NULL,
    discord_username VARCHAR(255) NOT NULL,
    linked_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id),
    UNIQUE KEY uq_discord_accounts_discord_user_id (discord_user_id),
    CONSTRAINT fk_discord_accounts_user_id FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- discord_username is a display convenience only (shown on the "Connect
-- Discord" settings row so a linked player can confirm which account is
-- linked) -- never used to address a notification, which always goes to
-- discord_user_id.

-- Short-lived CSRF state for the OAuth2 redirect round-trip -- the same
-- token-hash-with-expiry shape as email_verifications, just scoped to one
-- user's one in-flight "Connect Discord" click rather than a mailed link.
-- DiscordOAuthService deletes a state row the moment it's consumed
-- (single use) or once expired (checked, never swept -- these are tiny
-- and short-lived enough that unswept rows are noise, not a real cost).
CREATE TABLE IF NOT EXISTS discord_oauth_states (
    state_hash CHAR(64) NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    PRIMARY KEY (state_hash),
    CONSTRAINT fk_discord_oauth_states_user_id FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE schema_version SET version = '1.2.0' WHERE id = 1;
