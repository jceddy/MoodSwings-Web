-- Issue #419: the "Tactical Bot" tier -- a search-based (Monte Carlo
-- action evaluation over LegalChoiceEnumerator's own candidate actions,
-- see php-app/src/Bot/SearchBotPlayerService.php) alternative to the
-- existing heuristic practice bots (BotAlice/BotBen/BotCleo, migration
-- 0090), opt-in and separate -- the existing heuristic bots stay the
-- default/always-available option.
--
-- uses_tactical_ai marks a bot user (is_bot = 1) as one whose OWN-turn
-- play decisions should be handed off to the search engine instead of
-- the plain heuristic (BotPlayerService::chooseAction()) -- every other
-- bot decision (decision answers, team-decision proposals, draft picks)
-- is unaffected regardless of this flag; see SearchBotPlayerService's own
-- docblock for exactly what is -- and isn't -- searched. Meaningless for
-- a non-bot user (is_bot = 0), so no NOT NULL/default concerns beyond the
-- ordinary "off unless a bot row is deliberately flipped."
ALTER TABLE users
    ADD COLUMN uses_tactical_ai TINYINT(1) NOT NULL DEFAULT 0 AFTER is_bot;

-- A 4th named bot account -- one more than the maximum 3 non-creator
-- seats a 4-player game has, since a Tactical Bot seat is always chosen
-- deliberately (via the New Game dialog's own bot picker) rather than
-- being one of an interchangeable roster, so there's no need for 3 of
-- these the way the existing heuristic roster has. Same "real but
-- unreachable, never sent to" email / thrown-away random password
-- convention as migration 0090's own seeding.
INSERT INTO users (username, email, password_hash, share_presence, is_bot, uses_tactical_ai, email_verified_at) VALUES
    ('BotSage', 'bot-sage@moodswings.invalid', '$2y$12$BJnuYRV.my5EqqiRPQ0.6u4vO7Tn9Nek41URLvfcvsGFTo51MXJzq', 0, 1, 1, NOW());

-- Tracks one in-flight (or just-finished) background search job per bot
-- seat -- GameService::advanceAutomatedTurns() runs synchronously inline
-- with an HTTP request (see php-app/README.md's "Practice bots" section),
-- and a search call can legitimately take up to a couple of minutes,
-- which would otherwise hold every human-facing request that happens to
-- trigger this bot's turn open for that entire time. Instead,
-- advanceAutomatedTurns() launches a detached background PHP process
-- (bin/run_bot_search.php) the first time it sees a Tactical Bot's own
-- turn to play, inserting a 'running' row here immediately so the SAME
-- request (and every poll before the job finishes) can return right away
-- with a "the bot is thinking" indicator instead of blocking -- see
-- GameService::getState()'s own bot_thinking field. The background
-- process applies its chosen play through the exact same public
-- playMood()/pass() entry points a live request would use (so it's
-- subject to the same withGameLock() serialization as everything else),
-- then marks this row 'done' (or 'failed', with a message, on any
-- exception -- falling back to the ordinary fast heuristic bot inline so
-- the game is never left stuck on a dead job).
--
-- One game_player_id, not one per (game_player_id, "this turn") --
-- there's never more than one bot-search decision genuinely in flight for
-- a given seat at once (a seat only ever has one turn open at a time),
-- so a single most-recent row per seat is enough; a new turn's own job
-- simply INSERTs a fresh row rather than reusing/clearing the old one,
-- keeping a short history of recent decisions for free.
CREATE TABLE IF NOT EXISTS bot_search_jobs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    game_id INT UNSIGNED NOT NULL,
    game_player_id INT UNSIGNED NOT NULL,
    status ENUM('running', 'done', 'failed') NOT NULL DEFAULT 'running',
    time_budget_seconds SMALLINT UNSIGNED NOT NULL,
    started_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finished_at TIMESTAMP NULL DEFAULT NULL,
    error_message TEXT DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_bot_search_jobs_seat_status (game_player_id, status),
    CONSTRAINT fk_bot_search_jobs_game FOREIGN KEY (game_id) REFERENCES games (id) ON DELETE CASCADE,
    CONSTRAINT fk_bot_search_jobs_seat FOREIGN KEY (game_player_id) REFERENCES game_players (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE schema_version SET version = '1.33.0' WHERE id = 1;
