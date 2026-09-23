-- Achievements system (Phase 1 of the design doc's own build order): two
-- new tables, plus the initial 108-row catalog. `achievements` is static
-- reference data (one row per achievement); `user_achievements` is the
-- per-user progress/unlock table, lazily created and bumped the same
-- `INSERT ... ON DUPLICATE KEY UPDATE` way user_lifetime_stats/card_stats
-- already work.
--
-- `target` is the counter threshold a NEW_COUNTER/LIFETIME-style
-- achievement counts toward (e.g. 10 wins); NULL means a one-shot
-- condition unlocked directly, with nothing to count. `hidden` marks the
-- two spoiler-y meta rows (Mood Ring, Completionist) that shouldn't be
-- spoiled by name before they're earned.
--
-- No backfill from existing user_lifetime_stats: everyone starts every
-- counter at zero from this deploy forward (see the design doc's own
-- "Implementation notes" -- a one-time backfill pass for the LIFETIME
-- rows specifically can be added later if wanted).
CREATE TABLE IF NOT EXISTS achievements (
    id SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug VARCHAR(64) NOT NULL,
    category CHAR(1) NOT NULL,
    title VARCHAR(64) NOT NULL,
    description VARCHAR(255) NOT NULL,
    tier ENUM('Bronze', 'Silver', 'Gold', 'Platinum') NOT NULL,
    target INT UNSIGNED DEFAULT NULL,
    hidden TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_achievements_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rarity Collector ("play at least one copy of every mythic card in the
-- catalog") needs the growing SET of distinct mythic catalog ids a user
-- has ever played, not just a count -- a single running counter can't
-- express "every one at least once" when the same mythic gets replayed.
-- Recorded incrementally at game-completion time for the same reason
-- everything else here is (completed games are deleted after 7 days).
CREATE TABLE IF NOT EXISTS user_played_mythic_cards (
    user_id INT UNSIGNED NOT NULL,
    catalog_card_id SMALLINT UNSIGNED NOT NULL,
    PRIMARY KEY (user_id, catalog_card_id),
    CONSTRAINT fk_user_played_mythic_cards_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_user_played_mythic_cards_card FOREIGN KEY (catalog_card_id) REFERENCES cards (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Format Purist ("play 100 games in a single format") needs a PER-FORMAT
-- play count, not achievements.progress' one shared counter -- otherwise
-- switching formats between games would silently count toward the same
-- total instead of resetting the "single format" bar.
CREATE TABLE IF NOT EXISTS user_format_play_counts (
    user_id INT UNSIGNED NOT NULL,
    format VARCHAR(20) NOT NULL,
    games_played INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (user_id, format),
    CONSTRAINT fk_user_format_play_counts_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_achievements (
    user_id INT UNSIGNED NOT NULL,
    achievement_id SMALLINT UNSIGNED NOT NULL,
    progress INT UNSIGNED NOT NULL DEFAULT 0,
    unlocked_at TIMESTAMP NULL DEFAULT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, achievement_id),
    KEY idx_user_achievements_achievement (achievement_id),
    CONSTRAINT fk_user_achievements_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_user_achievements_achievement FOREIGN KEY (achievement_id) REFERENCES achievements (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 108 rows
INSERT INTO achievements (slug, category, title, description, tier, target, hidden) VALUES
('first-steps', 'A', 'First Steps', 'Win your first game', 'Bronze', 1, 0),
('getting-the-hang-of-it', 'A', 'Getting the Hang of It', 'Win 10 games', 'Bronze', 10, 0),
('seasoned-player', 'A', 'Seasoned Player', 'Win 50 games', 'Silver', 50, 0),
('veteran', 'A', 'Veteran', 'Win 200 games', 'Gold', 200, 0),
('legend', 'A', 'Legend', 'Win 1,000 games', 'Platinum', 1000, 0),
('foot-in-the-door', 'A', 'Foot in the Door', 'Complete your first game (win or lose)', 'Bronze', 1, 0),
('regular', 'A', 'Regular', 'Complete 100 games', 'Silver', 100, 0),
('no-days-off', 'A', 'No Days Off', 'Complete 500 games', 'Gold', 500, 0),
('match-point', 'A', 'Match Point', 'Win your first best-of-three match', 'Bronze', 1, 0),
('match-maker', 'A', 'Match Maker', 'Win 25 matches', 'Silver', 25, 0),
('grand-champion', 'A', 'Grand Champion', 'Win 100 matches', 'Gold', 100, 0),
('good-sport', 'A', 'Good Sport', 'Lose 50 games and keep playing anyway', 'Bronze', 50, 0),
('comeback-kid', 'A', 'Comeback Kid', 'Win a best-of-three match after losing game 1', 'Silver', NULL, 0),
('traditionalist', 'B', 'Traditionalist', 'Win 10 Traditional format games', 'Bronze', 10, 0),
('duelist', 'B', 'Duelist', 'Win 10 Duel format games', 'Bronze', 10, 0),
('team-spirit', 'B', 'Team Spirit', 'Win 10 Open Team Play games', 'Bronze', 10, 0),
('inner-circle', 'B', 'Inner Circle', 'Win 10 Closed Team Play games', 'Bronze', 10, 0),
('drafted', 'B', 'Drafted', 'Win 10 Draft-format games (any deck type)', 'Bronze', 10, 0),
('jack-of-all-formats', 'B', 'Jack of All Formats', 'Win at least 1 game in every format (Traditional, Duel, Open Team Play, Closed Team Play, Draft)', 'Silver', NULL, 0),
('format-purist', 'B', 'Format Purist', 'Play 100 games in a single format', 'Silver', 100, 0),
('renaissance-player', 'B', 'Renaissance Player', 'Win at least 10 games in every format', 'Gold', NULL, 0),
('quick-draw', 'C', 'Quick Draw', 'Win 10 Quick Draft games', 'Bronze', 10, 0),
('pile-driver', 'C', 'Pile Driver', 'Win 10 Winston Draft games', 'Bronze', 10, 0),
('grid-iron', 'C', 'Grid Iron', 'Win 10 Grid Draft games', 'Bronze', 10, 0),
('around-the-table', 'C', 'Around the Table', 'Win 10 Rotisserie Draft games', 'Bronze', 10, 0),
('tiered-up', 'C', 'Tiered Up', 'Win 10 Tiered Rotisserie Draft games', 'Bronze', 10, 0),
('chaos-agent', 'C', 'Chaos Agent', 'Win 10 Chaos Draft games', 'Bronze', 10, 0),
('sealed-with-a-kiss', 'C', 'Sealed With a Kiss', 'Win 10 Sealed Deck games', 'Bronze', 10, 0),
('pool-party', 'C', 'Pool Party', 'Win 10 Sealed Pool of the Day games', 'Bronze', 10, 0),
('weekly-regular', 'C', 'Weekly Regular', 'Participate in 10 Weekly Sealed Pool events', 'Silver', 10, 0),
('weekly-champion', 'C', 'Weekly Champion', 'Finish #1 in a Weekly Sealed Pool\'s standings', 'Gold', NULL, 0),
('structure-purist', 'C', 'Structure Purist', 'Win 10 games using the Structure deck', 'Bronze', 10, 0),
('jceddy-stan', 'C', 'jceddy Stan', 'Win 10 games using jceddy\'s 75 Card deck', 'Bronze', 10, 0),
('homebrewer', 'C', 'Homebrewer', 'Win 10 games using a Custom decklist', 'Bronze', 10, 0),
('draft-completionist', 'C', 'Draft Completionist', 'Win at least 1 game across every draft deck type (Quick/Winston/Grid/Rotisserie/Tiered Rotisserie/Chaos)', 'Gold', NULL, 0),
('randomizer', 'C', 'Randomizer', 'Win a Rotisserie Draft game created with the "randomize pool" option', 'Silver', NULL, 0),
('seeing-red', 'D', 'Seeing Red', 'Win 10 games with a majority-Red final board', 'Silver', 10, 0),
('true-blue', 'D', 'True Blue', 'Win 10 games with a majority-Blue final board', 'Silver', 10, 0),
('green-thumb', 'D', 'Green Thumb', 'Win 10 games with a majority-Green final board', 'Silver', 10, 0),
('white-knight', 'D', 'White Knight', 'Win 10 games with a majority-White final board', 'Silver', 10, 0),
('dark-arts', 'D', 'Dark Arts', 'Win 10 games with a majority-Black final board', 'Silver', 10, 0),
('rainbow-connection', 'D', 'Rainbow Connection', 'Win a game with all 5 colors represented on your final board', 'Silver', NULL, 0),
('mythic-hunter', 'D', 'Mythic Hunter', 'Play 100 mythic-rarity cards across all your games', 'Silver', 100, 0),
('common-touch', 'D', 'Common Touch', 'Win a game using only common and uncommon cards', 'Gold', NULL, 0),
('rarity-collector', 'D', 'Rarity Collector', 'Play at least one copy of every mythic card in the catalog', 'Gold', NULL, 0),
('color-wheel', 'D', 'Color Wheel', 'Win at least 10 games with each of the 5 colors as your majority', 'Platinum', NULL, 0),
('good-samaritan', 'D', 'Good Samaritan', 'Win a non-traditional game with Altruism, Benevolence, Charity, and Kindness all in your deck', 'Bronze', NULL, 0),
('spiral-of-dread', 'D', 'Spiral of Dread', 'Win a non-traditional game with Anxiety, Fear, Worry, Panic, and Neurosis all in your deck', 'Bronze', NULL, 0),
('inconsolable', 'D', 'Inconsolable', 'Win a non-traditional game with Grief, Melancholy, Misery, and Sadness all in your deck', 'Bronze', NULL, 0),
('hulk-smash', 'D', 'Hulk Smash!', 'Win a non-traditional game with Anger, Fury, Rage, and Wrath all in your deck', 'Bronze', NULL, 0),
('walking-on-sunshine', 'D', 'Walking on Sunshine', 'Win a non-traditional game with Happiness, Joy, Bliss, Delight, and Euphoria all in your deck', 'Bronze', NULL, 0),
('emotionally-balanced', 'D', 'Emotionally Balanced', 'Unlock all five color-synonym achievements: Good Samaritan, Spiral of Dread, Inconsolable, Hulk Smash!, and Walking on Sunshine', 'Silver', NULL, 0),
('high-roller', 'E', 'High Roller', 'Score 30+ points in a single round', 'Bronze', NULL, 0),
('point-explosion', 'E', 'Point Explosion', 'Score 45+ points in a single round', 'Silver', NULL, 0),
('nail-biter', 'E', 'Nail Biter', 'Win a round by exactly 1 point', 'Bronze', NULL, 0),
('flawless-victory', 'E', 'Flawless Victory', 'Win a best-of-three match without losing a single game', 'Silver', NULL, 0),
('perfect-round-record', 'E', 'Perfect Round Record', 'Win every round you play in a single match', 'Gold', NULL, 0),
('the-long-game', 'E', 'The Long Game', 'Win a match that lasted 10+ rounds', 'Silver', NULL, 0),
('speedrun', 'E', 'Speedrun', 'Win a game in 3 rounds or fewer', 'Silver', NULL, 0),
('hurt-feelings-survivor', 'E', 'Hurt Feelings Survivor', 'Win a round despite having Hurt Feelings that round', 'Bronze', NULL, 0),
('awe-some', 'E', 'Awe-some', 'Use Awe to cancel a round\'s scoring and still go on to win the match', 'Silver', NULL, 0),
('corruption-incarnate', 'E', 'Corruption Incarnate', 'Win a round using Corruption\'s double-win effect', 'Silver', NULL, 0),
('betrayer', 'E', 'Betrayer', 'Win a game after giving away and reclaiming a mood with Betrayal', 'Silver', NULL, 0),
('reckless-abandon', 'E', 'Reckless Abandon', 'Win a game after playing Recklessness', 'Bronze', NULL, 0),
('the-copycat', 'E', 'The Copycat', 'Win a game where Creativity copied a Mythic card', 'Silver', NULL, 0),
('sneak-attack', 'E', 'Sneak Attack', 'Win a game after playing Sneakiness and swapping scores', 'Bronze', NULL, 0),
('chain-reaction', 'E', 'Chain Reaction', 'Win a game where you played 3+ extra moods in a single turn via granted extra plays', 'Silver', NULL, 0),
('david-vs-goliath', 'E', 'David vs. Goliath', 'Win a game where your winning board\'s total value was lower than your opponent\'s', 'Gold', NULL, 0),
('practice-makes-perfect', 'E', 'Practice Makes Perfect', 'Win 25 games against a Tactical Bot', 'Bronze', 25, 0),
('diagnostic-detective', 'E', 'Diagnostic Detective', 'Win a Diagnostic Mode game against a Tactical Bot', 'Bronze', NULL, 0),
('clockwork', 'E', 'Clockwork', 'Win a game with a Total Time Limit enabled', 'Bronze', NULL, 0),
('deadline-dodger', 'E', 'Deadline Dodger', 'Win a game after receiving an idle-turn timeout warning that same turn', 'Silver', NULL, 0),
('bracketology', 'F', 'Bracketology', 'Join your first tournament', 'Bronze', 1, 0),
('podium-finish', 'F', 'Podium Finish', 'Reach the final round of a tournament', 'Silver', NULL, 0),
('tournament-champion', 'F', 'Tournament Champion', 'Win a tournament', 'Gold', 1, 0),
('grand-slam', 'F', 'Grand Slam', 'Win 3 tournaments', 'Platinum', 3, 0),
('swiss-movement', 'F', 'Swiss Movement', 'Win a Swiss-format tournament', 'Silver', NULL, 0),
('double-or-nothing', 'F', 'Double or Nothing', 'Win a Double Elimination tournament', 'Silver', NULL, 0),
('single-minded', 'F', 'Single-Minded', 'Win a Single Elimination tournament', 'Silver', NULL, 0),
('booster-buster', 'F', 'Booster Buster', 'Win a tournament that used Booster Draft', 'Gold', NULL, 0),
('open-door-policy', 'F', 'Open Door Policy', 'Win an open-registration tournament', 'Silver', NULL, 0),
('host-with-the-most', 'F', 'Host with the Most', 'Create a tournament that at least 8 players join', 'Bronze', NULL, 0),
('making-friends', 'G', 'Making Friends', 'Add your first friend', 'Bronze', 1, 0),
('social-butterfly', 'G', 'Social Butterfly', 'Have 10 accepted friends', 'Silver', 10, 0),
('deck-curator', 'G', 'Deck Curator', 'Save 5 custom decklists', 'Bronze', 5, 0),
('sharing-is-caring', 'G', 'Sharing is Caring', 'Share a saved decklist with a friend', 'Bronze', 1, 0),
('rematch', 'G', 'Rematch!', 'Play 5 games against the same opponent', 'Bronze', 5, 0),
('marathon-session', 'G', 'Marathon Session', 'Complete 3 games in a single calendar day', 'Bronze', 3, 0),
('spectator-sport', 'G', 'Spectator Sport', 'Spectate a game', 'Bronze', 1, 0),
('discord-connected', 'G', 'Discord Connected', 'Link your Discord account', 'Bronze', 1, 0),
('replay-enthusiast', 'G', 'Replay Enthusiast', 'Import a replay', 'Bronze', 1, 0),
('bot-wrangler', 'G', 'Bot Wrangler', 'Create a game seating 2 or more practice bots', 'Bronze', 1, 0),
('night-owl', 'H', 'Night Owl', 'Complete a game between midnight and 4am your local time', 'Bronze', 1, 0),
('early-bird', 'H', 'Early Bird', 'Complete a game between 5am and 7am your local time', 'Bronze', 1, 0),
('deck-doctor', 'H', 'Deck Doctor', 'Edit a saved decklist after creating it', 'Bronze', 1, 0),
('card-counter', 'H', 'Card Counter', 'View the server-wide card stats page', 'Bronze', 1, 0),
('explorer', 'H', 'Explorer', 'Try every format + deck type combination the game offers at least once', 'Platinum', NULL, 0),
('default-setting', 'H', 'Default Setting', 'Complete 10 games using Default Selections Mode', 'Bronze', 10, 0),
('against-the-clock', 'H', 'Against the Clock', 'Win a game with a turn/decision timeout enabled', 'Bronze', NULL, 0),
('mood-ring', 'H', 'Mood Ring', 'Unlock at least one achievement from every other category in this list', 'Gold', NULL, 1),
('completionist', 'H', 'Completionist', 'Unlock every other achievement', 'Platinum', NULL, 1),
('vanilla-extract', 'I', 'Vanilla Extract', 'Win a non-traditional game with Complacency, Indifference, Apathy, Boredom, and Laziness all in your deck', 'Bronze', NULL, 0),
('birds-of-a-feather', 'I', 'Birds of a Feather', 'Win a non-traditional game with Loyalty, Obsession, Pity, Excitement, and Enjoyment all in your deck', 'Bronze', NULL, 0),
('bad-blood', 'I', 'Bad Blood', 'Win a non-traditional game with Discipline, Ambivalence, Disgust, Frustration, and Disregard all in your deck', 'Bronze', NULL, 0),
('encore', 'I', 'Encore!', 'Win a non-traditional game with Charity, Fear, Ambition, Bravado, and Nostalgia all in your deck', 'Bronze', NULL, 0),
('in-good-company', 'I', 'In Good Company', 'Win a non-traditional game with Faith, Worry, Angst, Hostility, and Happiness all in your deck', 'Bronze', NULL, 0),
('thats-gotta-hurt', 'I', 'That\'s Gotta Hurt', 'Win a non-traditional game with Guilt, Hesitation, Contempt, Arrogance, and Fascination all in your deck', 'Bronze', NULL, 0),
('spin-cycle', 'I', 'Spin Cycle', 'Unlock all six card-cycle achievements: Vanilla Extract, Birds of a Feather, Bad Blood, Encore!, In Good Company, and That\'s Gotta Hurt', 'Silver', NULL, 0);

UPDATE schema_version SET version = '1.51.0' WHERE id = 1;
