-- ============================================================================
-- CONSOLIDATED migrations 0001 through 0020, for MANUAL use only.
-- ============================================================================
--
-- This is every statement from migrations/0001_baseline.sql through
-- migrations/0020_add_duel_even_color_distribution_rule.sql, concatenated in
-- their original order, for standing up a fresh database in one paste (e.g.
-- via phpMyAdmin's SQL tab) instead of running 20 separate files by hand.
-- It is NOT itself a migration and is deliberately kept out of
-- database/migrations/ -- bin/migrate.php only globs *.sql directly in that
-- directory, so this file living in database/consolidated/ instead means it
-- can never be picked up (or re-applied) by the normal migration runner.
--
-- Only use this against a genuinely empty/fresh database. Every CREATE
-- TABLE below is IF NOT EXISTS and every ALTER TABLE is a plain (non-
-- idempotent) column/index/FK change exactly as it shipped -- running this
-- against a database that already has some, but not all, of these
-- migrations applied will fail partway through (e.g. a column that already
-- exists) rather than skipping what's already there the way
-- `composer migrate` does.
--
-- After running this, the migration-tracking table itself is created and
-- pre-populated with all 20 filenames below (see the bottom of this file),
-- so `composer migrate` correctly recognizes 0001-0020 as already applied
-- and only ever applies 0021 onward from here.
--
-- Regenerate this file (by hand) if migrations/ ever gains new files you
-- want folded into a future consolidated script covering more of the
-- history -- it is a point-in-time snapshot, not automatically kept in
-- sync with migrations/ as new ones are added.

-- ---------------------------------------------------------------------------
-- 0001_baseline.sql
-- ---------------------------------------------------------------------------
-- Baseline: the full schema as of when the migrations workflow was
-- introduced. If your database was already provisioned before this (i.e.
-- you'd previously run the old schema.sql), you don't need to run this one
-- — start from whichever migration comes next. Safe to run on an empty
-- database or replay on one that already matches it (CREATE TABLE IF NOT
-- EXISTS everywhere).

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    username VARCHAR(64) NOT NULL,
    email VARCHAR(255) NOT NULL,
    phone_number VARCHAR(20) DEFAULT NULL,
    password_hash VARCHAR(255) NOT NULL,
    email_verified_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_username (username),
    UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tracks logged-in sessions. The cookie holds a random token; only its
-- SHA-256 hash is stored, so a database leak alone can't be used to log in.
CREATE TABLE IF NOT EXISTS sessions (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NOT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    user_agent VARCHAR(255) DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sessions_token_hash (token_hash),
    KEY idx_sessions_user_id (user_id),
    KEY idx_sessions_expires_at (expires_at),
    CONSTRAINT fk_sessions_user_id FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Single-use email verification links sent at registration. Same hashing
-- rationale as sessions above: only a SHA-256 hash of the token is stored.
CREATE TABLE IF NOT EXISTS email_verifications (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_email_verifications_token_hash (token_hash),
    KEY idx_email_verifications_user_id (user_id),
    CONSTRAINT fk_email_verifications_user_id FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 0002_create_friendships_table.sql
-- ---------------------------------------------------------------------------
-- Friend relationships between two users. One row per unordered pair
-- (user_low_id, user_high_id are always sorted ascending by the app layer),
-- so a pair of users can only ever have a single relationship row no
-- matter who initiated it.
--
-- status: 'pending' (invite awaiting a response), 'accepted' (mutual
-- friends), or 'blocked'. action_user_id's meaning depends on status: who
-- sent the pending invite, or who performed the block.
CREATE TABLE IF NOT EXISTS friendships (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_low_id INT UNSIGNED NOT NULL,
    user_high_id INT UNSIGNED NOT NULL,
    status ENUM('pending', 'accepted', 'blocked') NOT NULL,
    action_user_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_friendships_pair (user_low_id, user_high_id),
    KEY idx_friendships_user_high_id (user_high_id),
    CONSTRAINT fk_friendships_user_low FOREIGN KEY (user_low_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_friendships_user_high FOREIGN KEY (user_high_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT chk_friendships_order CHECK (user_low_id < user_high_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 0003_create_card_catalog.sql
-- ---------------------------------------------------------------------------
-- Reference data: the full 133-card Mood Swings pool (White/Blue/Black/Red/
-- Green, 26-27 cards each). This is catalog data, not per-user data -- it
-- never changes per game, so it's seeded here rather than written by the
-- app.
--
-- Notably absent: Hurt Feelings. It's a marker/token that's never in a
-- deck, hand, or discard pile -- physically it just indicates which player
-- currently gets to play an extra mood, a status entirely determined by
-- the previous round's score. It's modeled in 0004_create_game_tables.sql
-- as an attribute of a round (game_rounds.hurt_feelings_game_player_id),
-- not as a row here. The Headliner treatment of Love is also omitted: per
-- its own reminder text it's "mechanically identical" to the regular Love
-- printing (row 127 below), just alternate art, so it isn't a distinct
-- gameplay object either.
--
-- base_value/alt_value are the plain numeric values used for scoring;
-- rules_text spells out the condition under which alt_value (if any)
-- applies, or the effect that happens (this is intentionally the
-- printed-card text with die-face icons normalized to plain numbers, not a
-- reformulation -- it's the source of truth a future rules engine should
-- implement against). has_to_play_ability / has_while_in_play_ability /
-- has_after_playing_ability flag which of the three ability timings (see
-- the Extended Rules' resolution order) a card has; a card can have more
-- than one (e.g. Guile has both a "to play" cost and an "after playing"
-- effect). Five commons (one per color) and Creativity have none of the
-- three -- they're the "vanilla" cards.
--
-- effect_key is a stable slug for game-engine code to key its per-card
-- effect implementation off of, since encoding 133 distinct effects (each
-- with its own choices, targets, and quantities) as structured columns
-- isn't practical -- the actual gameplay logic lives in code, dispatched
-- by this key, with rules_text as the spec it implements.
CREATE TABLE IF NOT EXISTS cards (
    id SMALLINT UNSIGNED NOT NULL,
    name VARCHAR(40) NOT NULL,
    effect_key VARCHAR(40) NOT NULL,
    color ENUM('white', 'blue', 'black', 'red', 'green') NOT NULL,
    rarity ENUM('common', 'uncommon', 'rare', 'mythic') NOT NULL,
    base_value TINYINT UNSIGNED NOT NULL,
    alt_value TINYINT UNSIGNED DEFAULT NULL,
    has_to_play_ability TINYINT(1) NOT NULL DEFAULT 0,
    has_while_in_play_ability TINYINT(1) NOT NULL DEFAULT 0,
    has_after_playing_ability TINYINT(1) NOT NULL DEFAULT 0,
    rules_text TEXT NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cards_name (name),
    UNIQUE KEY uq_cards_effect_key (effect_key),
    KEY idx_cards_color (color)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO cards (id, name, effect_key, color, rarity, base_value, alt_value, has_to_play_ability, has_while_in_play_ability, has_after_playing_ability, rules_text) VALUES
(1, 'Altruism', 'altruism', 'white', 'rare', 3, 6, 0, 0, 1, 'After playing this mood, if the discard pile has at least one card in it, this mood''s value becomes 6. Then, starting with the next player in turn order, each player takes a random card from the discard pile and puts it into their hand. Put the rest of the discard pile onto the bottom of the deck in a random order.'),
(2, 'Benevolence', 'benevolence', 'white', 'uncommon', 2, NULL, 0, 0, 1, 'After playing this mood, you may play an additional mood this turn if it doesn''t share a color with any of your moods.'),
(3, 'Charity', 'charity', 'white', 'common', 1, NULL, 0, 0, 1, 'After playing this mood, you may play an additional mood this turn.'),
(4, 'Chivalry', 'chivalry', 'white', 'common', 3, 5, 0, 1, 0, 'While in play, this mood''s value is 5 if you didn''t go first this round.'),
(5, 'Complacency', 'complacency', 'white', 'common', 4, NULL, 0, 0, 0, 'No ability. One of five vanilla commons, one per color, each worth a flat 4.'),
(6, 'Conviction', 'conviction', 'white', 'uncommon', 2, NULL, 0, 0, 1, 'After playing this mood, choose a mood. Its player puts it on the bottom of the deck and draws a card.'),
(7, 'Courage', 'courage', 'white', 'common', 1, NULL, 0, 0, 1, 'After playing this mood, choose up to two players. For each chosen player, put one of their moods with a value of 5 or more into the discard pile.'),
(8, 'Dignity', 'dignity', 'white', 'common', 3, 5, 0, 0, 1, 'After playing this mood, you may discard a card from your hand with a 0, 1, 2, or 3 in its top right corner. If you do, this mood''s value becomes 5.'),
(9, 'Discipline', 'discipline', 'white', 'common', 6, 3, 0, 1, 0, 'While in play, this mood''s value is 3 if there are two or more black and/or red moods.'),
(10, 'Disillusionment', 'disillusionment', 'white', 'rare', 2, NULL, 0, 0, 1, 'After playing this mood, starting with the next player in turn order, each player may choose a color. Put each other mood that shares one of those colors into the discard pile.'),
(11, 'Encouragement', 'encouragement', 'white', 'uncommon', 3, NULL, 0, 1, 1, 'After playing this mood, you may choose a mood with dice in its lower left corner. While in play, the chosen mood uses the dice total in its top right corner or lower left corner, whichever is higher, to determine its value.'),
(12, 'Faith', 'faith', 'white', 'uncommon', 3, NULL, 0, 0, 1, 'After playing this mood, you may discard a green or blue card from your hand. If you do, suppress any mood. It remains suppressed for as long as you have this mood.'),
(13, 'Friendliness', 'friendliness', 'white', 'uncommon', 2, NULL, 0, 0, 1, 'After playing this mood, you may play an additional mood this turn if it has a 0, 2, 4, or 6 in its top right corner.'),
(14, 'Guilt', 'guilt', 'white', 'uncommon', 2, NULL, 0, 0, 1, 'After playing this mood, choose one: suppress a black or red mood for as long as you have this mood, or suppress all black and red moods for as long as you have this mood.'),
(15, 'Honor', 'honor', 'white', 'rare', 3, NULL, 0, 1, 1, 'After playing this mood, choose a player. While in play, the chosen player goes first each round regardless of who won the previous round.'),
(16, 'Idealism', 'idealism', 'white', 'mythic', 0, NULL, 0, 1, 1, 'After playing this mood, you may play an additional mood this turn. While in play, for each of your moods with dice in its lower left corner, use the dice total in its top right corner or lower left corner, whichever is higher, to determine its value.'),
(17, 'Kindness', 'kindness', 'white', 'uncommon', 2, NULL, 0, 0, 1, 'After playing this mood, you may play an additional mood this turn if it has a 1, 3, or 5 in its top right corner.'),
(18, 'Loyalty', 'loyalty', 'white', 'common', 3, 6, 0, 1, 0, 'While in play, this mood''s value is 6 if there are two or more green and/or blue moods.'),
(19, 'Meekness', 'meekness', 'white', 'rare', 1, NULL, 0, 0, 1, 'After playing this mood, suppress all moods with a value of 5 or more. Those moods remain suppressed for as long as you have this mood.'),
(20, 'Pacifism', 'pacifism', 'white', 'common', 1, NULL, 0, 0, 1, 'After playing this mood, choose up to two players. For each chosen player, suppress one of their moods. It remains suppressed for as long as you have this mood.'),
(21, 'Patience', 'patience', 'white', 'common', 5, 1, 0, 1, 0, 'While in play, this mood''s value is 1 if you played it this round.'),
(22, 'Pride', 'pride', 'white', 'rare', 1, NULL, 0, 0, 1, 'After playing this mood, you may choose a player with more moods than you. If you do, you may keep playing additional moods this turn until you have as many moods as the chosen player.'),
(23, 'Repentance', 'repentance', 'white', 'uncommon', 2, NULL, 0, 0, 1, 'After playing this mood, you may choose a number. If you do, suppress all other moods with the chosen value. They remain suppressed until the end of this round.'),
(24, 'Scorn', 'scorn', 'white', 'mythic', 2, NULL, 0, 1, 1, 'After playing this mood, suppress any mood until the end of this round. While in play, each time you play another mood, you may suppress a mood that shares a color with it until the end of this round.'),
(25, 'Shame', 'shame', 'white', 'rare', 3, NULL, 0, 0, 1, 'After playing this mood, you may discard a card from your hand. If you do, suppress all other moods that share a color with the discarded card. Those moods remain suppressed for as long as you have this mood.'),
(26, 'Validation', 'validation', 'white', 'mythic', 1, NULL, 0, 1, 1, 'After playing this mood, you may play an additional mood this turn. While in play, each time you play another mood with a 0 or 1 in its top right corner, you may play an additional mood this turn.'),
(27, 'Ambivalence', 'ambivalence', 'blue', 'common', 6, 3, 0, 1, 0, 'While in play, this mood''s value is 3 if there are two or more red and/or green moods.'),
(28, 'Anxiety', 'anxiety', 'blue', 'common', 2, NULL, 0, 0, 1, 'After playing this mood, choose up to two players. For each chosen player, put one of their moods with an odd value into their hand.'),
(29, 'Avoidance', 'avoidance', 'blue', 'rare', 3, NULL, 0, 0, 1, 'After playing this mood, choose left or right. Each player chooses one of their moods and gives it to the next player in the chosen direction.'),
(30, 'Bashfulness', 'bashfulness', 'blue', 'common', 6, NULL, 0, 0, 1, 'After playing this mood, after scoring this round, if you won the round, put this mood on the bottom of the deck and draw a card.'),
(31, 'Confusion', 'confusion', 'blue', 'uncommon', 4, NULL, 0, 0, 1, 'After playing this mood, choose left or right. Each player chooses a card from their hand and gives it to the next player in the chosen direction.'),
(32, 'Creativity', 'creativity', 'blue', 'rare', 0, NULL, 0, 0, 0, 'You may play this card as a copy of any mood, treating it as an exact copy of that printed card (including dice, color, and abilities) for as long as it''s in play. If you don''t, it''s just a blue card worth 0.'),
(33, 'Curiosity', 'curiosity', 'blue', 'common', 3, 6, 0, 0, 1, 'After playing this mood, you may choose a player. If you do, that player reveals a random card from their hand. If the revealed card shares a color with any mood, this mood''s value becomes 6.'),
(34, 'Denial', 'denial', 'blue', 'rare', 1, NULL, 0, 0, 1, 'After playing this mood, you may choose two other moods. If the two chosen moods share a color or have the same value, put them into their players'' hands.'),
(35, 'Disorientation', 'disorientation', 'blue', 'rare', 0, NULL, 0, 0, 1, 'After playing this mood, you may choose a number. If you do, put all other moods with the chosen value into their players'' hands.'),
(36, 'Doubt', 'doubt', 'blue', 'uncommon', 2, NULL, 0, 0, 1, 'After playing this mood, you may reveal any number of cards from your hand and put them on the bottom of the deck, then draw that many cards. During the next round, players can''t play moods that share a color with any of the revealed cards.'),
(37, 'Duplicity', 'duplicity', 'blue', 'mythic', 0, NULL, 0, 1, 1, 'After playing this mood, you may play an additional mood this turn. While in play, each time you play another mood, you may have that mood''s after-playing effect happen an additional time.'),
(38, 'Fear', 'fear', 'blue', 'common', 0, NULL, 0, 0, 1, 'After playing this mood, you may put another one of your moods into your hand. You may play an additional mood this turn.'),
(39, 'Fickleness', 'fickleness', 'blue', 'uncommon', 0, NULL, 0, 0, 1, 'After playing this mood, calculate the most common color or colors among all moods. Put all moods other than this one that share one of those colors into their players'' hands.'),
(40, 'Guile', 'guile', 'blue', 'mythic', 0, NULL, 1, 0, 1, 'To play this card, discard two cards from your hand. If you can''t do that, you can''t play this card. After playing this mood, choose one of your opponents'' moods. It becomes yours.'),
(41, 'Hesitation', 'hesitation', 'blue', 'uncommon', 2, NULL, 0, 0, 1, 'After playing this mood, choose one: put a red or green mood into its player''s hand, or put all red and green moods into their players'' hands.'),
(42, 'Imagination', 'imagination', 'blue', 'uncommon', 3, NULL, 0, 1, 1, 'After playing this mood, choose a color. While in play, all moods are the chosen color and no other colors.'),
(43, 'Indecisiveness', 'indecisiveness', 'blue', 'uncommon', 3, NULL, 0, 0, 1, 'After playing this mood, choose any number of opponents who each have two or more moods. Each chosen player puts a random one of their moods into their hand.'),
(44, 'Indifference', 'indifference', 'blue', 'common', 4, NULL, 0, 0, 0, 'No ability. One of five vanilla commons, one per color, each worth a flat 4.'),
(45, 'Insecurity', 'insecurity', 'blue', 'uncommon', 2, NULL, 0, 0, 1, 'After playing this mood, you may play an additional mood this turn. If you do, after scoring, put that mood into your hand if it''s still in play.'),
(46, 'Neurosis', 'neurosis', 'blue', 'common', 5, NULL, 1, 0, 0, 'To play this card, put one or more of your moods into your hand. If you can''t do that, you can''t play this card.'),
(47, 'Obsession', 'obsession', 'blue', 'common', 3, 6, 0, 1, 0, 'While in play, this mood''s value is 6 if there are two or more white and/or black moods.'),
(48, 'Panic', 'panic', 'blue', 'common', 1, NULL, 0, 0, 1, 'After playing this mood, choose up to two players. For each chosen player, put one of their moods into their hand. You can''t put this mood into your hand this way.'),
(49, 'Rationalization', 'rationalization', 'blue', 'rare', 3, NULL, 0, 0, 1, 'After playing this mood, choose one: put your hand on the bottom of the deck then draw that many cards, or choose left or right and have each player simultaneously give their hand to the next player in that direction.'),
(50, 'Regret', 'regret', 'blue', 'rare', 4, NULL, 1, 0, 1, 'To play this card, put two of your moods into your hand. If you can''t do that, you can''t play this card. After playing this mood, put an opponent''s mood into your hand.'),
(51, 'Sneakiness', 'sneakiness', 'blue', 'mythic', 5, NULL, 0, 0, 1, 'After playing this mood, choose an opponent. This round, after scoring, swap your score with that player before determining who wins the round.'),
(52, 'Worry', 'worry', 'blue', 'uncommon', 3, NULL, 0, 0, 1, 'After playing this mood, you may put one of your white or black moods into your hand. If you do, put up to two moods other than this one, each with a value of 3 or less, into their players'' hands.'),
(53, 'Ambition', 'ambition', 'black', 'common', 2, NULL, 0, 0, 1, 'After playing this mood, you may discard a card from your hand. If you do, you may play an additional mood this turn.'),
(54, 'Angst', 'angst', 'black', 'uncommon', 3, NULL, 0, 0, 1, 'After playing this mood, you may put one of your blue or red moods into the discard pile. If you do, you may play an additional mood this turn from the discard pile.'),
(55, 'Apathy', 'apathy', 'black', 'common', 4, NULL, 0, 0, 0, 'No ability. One of five vanilla commons, one per color, each worth a flat 4.'),
(56, 'Betrayal', 'betrayal', 'black', 'uncommon', 6, NULL, 0, 0, 1, 'After playing this mood, give one of your moods to another player. After scoring, that mood becomes yours again if it''s still in play.'),
(57, 'Bitterness', 'bitterness', 'black', 'uncommon', 0, NULL, 0, 0, 1, 'After playing this mood, calculate the most common color or colors among all moods. Put all other moods that share one of those colors into the discard pile.'),
(58, 'Condescension', 'condescension', 'black', 'common', 3, 6, 0, 0, 1, 'After playing this mood, you may give a card from your hand to another player. If you do, this mood''s value becomes 6.'),
(59, 'Contempt', 'contempt', 'black', 'uncommon', 1, NULL, 0, 0, 1, 'After playing this mood, you may choose one: put a green or white mood into the discard pile, or put all green and white moods into the discard pile.'),
(60, 'Corruption', 'corruption', 'black', 'rare', 2, NULL, 0, 0, 1, 'After playing this mood, you may choose one: put up to two cards from the discard pile on the bottom of the deck then draw that many cards, or the winner of the current round wins two rounds instead of one (each losing player still draws only one card).'),
(61, 'Cruelty', 'cruelty', 'black', 'uncommon', 3, NULL, 0, 0, 1, 'After playing this mood, choose any number of opponents who each have two or more moods. Each chosen opponent puts a random one of their moods into the discard pile.'),
(62, 'Cynicism', 'cynicism', 'black', 'uncommon', 3, 6, 0, 0, 1, 'After playing this mood, you may put a card from the discard pile into an opponent''s hand. If you do, this mood''s value becomes 6.'),
(63, 'Disgust', 'disgust', 'black', 'common', 6, 3, 0, 1, 0, 'While in play, this mood''s value is 3 if there are two or more green and/or white moods.'),
(64, 'Envy', 'envy', 'black', 'rare', 0, NULL, 1, 1, 0, 'To play this card, put one of your moods into the discard pile. If you can''t do that, you can''t play this card. While in play, this mood''s value increases by 2 for each mood your moodiest opponent (the opponent with the most moods) has.'),
(65, 'Grief', 'grief', 'black', 'rare', 0, NULL, 0, 0, 1, 'After playing this mood, you may play up to two additional moods this turn from the discard pile.'),
(66, 'Hate', 'hate', 'black', 'common', 0, NULL, 0, 0, 1, 'After playing this mood, you may put any mood on the bottom of the deck. If you do, draw a card.'),
(67, 'Intimidation', 'intimidation', 'black', 'rare', 1, NULL, 0, 0, 1, 'After playing this mood, you may choose another player. If you do, that player reveals a card from their hand and puts it into your hand. You may play it as an additional mood this turn.'),
(68, 'Malice', 'malice', 'black', 'mythic', 0, NULL, 0, 0, 1, 'After playing this mood, choose any player who has two or more moods. That player chooses two of their moods. Put those moods, and all other moods that share a color with either of them, into the discard pile.'),
(69, 'Melancholy', 'melancholy', 'black', 'rare', 3, NULL, 0, 1, 0, 'While in play, you may play moods from the discard pile as though they were in your hand.'),
(70, 'Misery', 'misery', 'black', 'uncommon', 2, 6, 0, 1, 0, 'While in play, this mood''s value is 6 if there are two or more cards in the discard pile that share a color.'),
(71, 'Paranoia', 'paranoia', 'black', 'uncommon', 2, NULL, 0, 0, 1, 'After playing this mood, you may choose a player with one or more cards in their hand. If you do, that player reveals a random card from their hand and puts it on the bottom of the deck, then you draw a card.'),
(72, 'Pity', 'pity', 'black', 'common', 3, 6, 0, 1, 0, 'While in play, this mood''s value is 6 if there are two or more blue and/or red moods.'),
(73, 'Rejection', 'rejection', 'black', 'rare', 0, NULL, 0, 0, 1, 'After playing this mood, you may choose two other moods. If the two chosen moods share a color or have the same value, put them into the discard pile.'),
(74, 'Sadness', 'sadness', 'black', 'mythic', 0, NULL, 0, 1, 0, 'While in play, this mood''s value increases by 2 for each card in the discard pile.'),
(75, 'Self-Loathing', 'self_loathing', 'black', 'common', 6, NULL, 1, 0, 0, 'To play this card, put one or more of your moods into the discard pile. If you can''t do that, you can''t play this card.'),
(76, 'Spite', 'spite', 'black', 'common', 1, NULL, 0, 0, 1, 'After playing this mood, choose up to two players. For each chosen player, put one of their moods with an even value into the discard pile (0 is even).'),
(77, 'Superiority', 'superiority', 'black', 'common', 3, 6, 0, 1, 0, 'While in play, this mood''s value is 6 if you have more moods than each other player.'),
(78, 'Suspicion', 'suspicion', 'black', 'common', 3, NULL, 0, 0, 1, 'After playing this mood, choose any number of players. Each chosen player discards a card from their hand.'),
(79, 'Vanity', 'vanity', 'black', 'mythic', 0, NULL, 0, 1, 0, 'While in play, this mood''s value increases by 1 for each of your moods (including itself). If there are no cards in your hand, this mood''s value instead increases by 3 for each of your moods (including itself).'),
(80, 'Anger', 'anger', 'red', 'uncommon', 0, NULL, 0, 0, 1, 'After playing this mood, you may put any number of moods with total value 5 or less into the discard pile.'),
(81, 'Animosity', 'animosity', 'red', 'uncommon', 3, 5, 0, 1, 0, 'While in play, this mood''s value is 5 if any opponent has three or more cards in hand.'),
(82, 'Arrogance', 'arrogance', 'red', 'uncommon', 2, NULL, 0, 0, 1, 'After playing this mood, you may choose an opponent. If you do, they choose one of their white or blue moods and it becomes yours. After this mood is no longer in play, give the mood you took back to them if you still have it.'),
(83, 'Boredom', 'boredom', 'red', 'common', 4, NULL, 0, 0, 0, 'No ability. One of five vanilla commons, one per color, each worth a flat 4.'),
(84, 'Bravado', 'bravado', 'red', 'common', 3, NULL, 0, 0, 1, 'After playing this mood, you may put one of your other moods into the discard pile. If you do, you may play an additional mood this turn.'),
(85, 'Chaos', 'chaos', 'red', 'mythic', 6, NULL, 0, 0, 1, 'After playing this mood, shuffle all moods together. Starting with you and going in turn order, deal those moods out one at a time to each player. Moods may change players, but their after-playing effects don''t happen again.'),
(86, 'Compulsion', 'compulsion', 'red', 'common', 3, NULL, 0, 0, 1, 'After playing this mood, choose another player. That player chooses a card from their hand and gives it to you.'),
(87, 'Embarrassment', 'embarrassment', 'red', 'common', 3, 5, 0, 0, 1, 'After playing this mood, you may discard a card from your hand with a 4, 5, or 6 in its top right corner. If you do, this mood''s value becomes 5.'),
(88, 'Excitement', 'excitement', 'red', 'common', 3, 6, 0, 1, 0, 'While in play, this mood''s value is 6 if there are two or more black and/or green moods.'),
(89, 'Exhilaration', 'exhilaration', 'red', 'mythic', 0, NULL, 1, 1, 0, 'To play this card, put one of your moods into the discard pile. If you can''t do that, you can''t play this card. While in play, score your moods an extra time.'),
(90, 'Frustration', 'frustration', 'red', 'common', 6, 3, 0, 1, 0, 'While in play, this mood''s value is 3 if there are two or more white and/or blue moods.'),
(91, 'Fury', 'fury', 'red', 'uncommon', 4, NULL, 0, 0, 1, 'After playing this mood, each player chooses one of their highest value moods and puts it into the discard pile.'),
(92, 'Glee', 'glee', 'red', 'common', 0, 6, 0, 1, 0, 'While in play, this mood''s value is 6 if you played it this round.'),
(93, 'Gluttony', 'gluttony', 'red', 'uncommon', 2, NULL, 0, 0, 1, 'After playing this mood, you may play an additional mood this turn. If you do, after scoring, put that mood into the discard pile if it''s still in play.'),
(94, 'Hostility', 'hostility', 'red', 'uncommon', 3, NULL, 0, 0, 1, 'After playing this mood, you may put one of your black or green moods into the discard pile. If you do, put up to two moods, each with a value of 3 or less, into the discard pile.'),
(95, 'Infatuation', 'infatuation', 'red', 'rare', 3, 6, 0, 0, 1, 'After playing this mood, you may put two of your other moods into the discard pile. If you do, this mood''s value becomes 6.'),
(96, 'Instability', 'instability', 'red', 'rare', 2, NULL, 0, 0, 1, 'After playing this mood, you may choose two moods from the same opponent. If you do, they choose one of those moods and give it to you, then you give them one of your moods.'),
(97, 'Passion', 'passion', 'red', 'rare', 0, NULL, 0, 1, 0, 'While in play, while scoring, you may score one of your opponents'' moods as though it were yours (they also still score it).'),
(98, 'Rage', 'rage', 'red', 'uncommon', 2, NULL, 0, 0, 1, 'After playing this mood, you may put all other moods with a value of 3 or less into the discard pile.'),
(99, 'Rebellion', 'rebellion', 'red', 'uncommon', 2, NULL, 0, 0, 1, 'After playing this mood, choose 0, 1, 2, or 3. Put all other moods with the chosen value into the discard pile.'),
(100, 'Recklessness', 'recklessness', 'red', 'rare', 0, NULL, 0, 1, 1, 'After playing this mood, you may take one of your opponents'' moods. If you do, after scoring, give the mood you took back to them if you still have it. While in play, after scoring, put this mood on the bottom of the deck and draw a card.'),
(101, 'Shock', 'shock', 'red', 'common', 2, NULL, 0, 0, 1, 'After playing this mood, choose up to two players. For each chosen player, put one of their moods with a value of 3 or less into the discard pile.'),
(102, 'Stubbornness', 'stubbornness', 'red', 'rare', 3, NULL, 0, 1, 0, 'While in play, at the start of each of your turns, if another player has more moods than you, you may play an additional mood this turn.'),
(103, 'Thrill', 'thrill', 'red', 'mythic', 1, NULL, 0, 0, 1, 'After playing this mood, you may put any number of your other moods into your hand. If you do, you may play that many additional moods this turn.'),
(104, 'Triumph', 'triumph', 'red', 'common', 3, 5, 0, 1, 0, 'While in play, this mood''s value is 5 if you went first this round.'),
(105, 'Wrath', 'wrath', 'red', 'rare', 0, NULL, 0, 0, 1, 'After playing this mood, you may put all other moods into the discard pile.'),
(106, 'Zeal', 'zeal', 'red', 'common', 3, NULL, 0, 0, 1, 'After playing this mood, you may put a card from your hand on the bottom of the deck. If you do, draw a card.'),
(107, 'Awe', 'awe', 'green', 'rare', 4, NULL, 0, 0, 1, 'After playing this mood, there is no scoring this round. No one wins or loses this round. You choose which player goes first next round. (No one draws a card or gets Hurt Feelings for this round, and after-scoring effects don''t happen.)'),
(108, 'Bliss', 'bliss', 'green', 'mythic', 2, NULL, 1, 1, 0, 'To play this card, discard a card from your hand. If you can''t do that, you can''t play this card. While in play, score each of your moods that shares a color with the discarded card two extra times.'),
(109, 'Celebration', 'celebration', 'green', 'common', 3, 6, 0, 1, 0, 'While in play, this mood''s value is 6 if there are more colors among your moods than among each other player''s moods.'),
(110, 'Cheer', 'cheer', 'green', 'common', 3, 5, 0, 0, 1, 'After playing this mood, you may discard a card from your hand with a 0, 2, 4, or 6 in its top right corner. If you do, this mood''s value becomes 5.'),
(111, 'Delight', 'delight', 'green', 'common', 3, 5, 0, 0, 1, 'After playing this mood, you may discard a card from your hand with a 1, 3, or 5 in its top right corner. If you do, this mood''s value becomes 5.'),
(112, 'Determination', 'determination', 'green', 'common', 3, 6, 0, 1, 0, 'While in play, this mood''s value is 6 if there are three or more moods that share a color.'),
(113, 'Disregard', 'disregard', 'green', 'common', 6, 3, 0, 1, 0, 'While in play, this mood''s value is 3 if there are two or more blue and/or black moods.'),
(114, 'Eagerness', 'eagerness', 'green', 'uncommon', 2, NULL, 0, 0, 1, 'After playing this mood, you may play an additional mood this turn if it shares a color with one of your moods.'),
(115, 'Enjoyment', 'enjoyment', 'green', 'common', 3, 6, 0, 1, 0, 'While in play, this mood''s value is 6 if there are two or more red and/or white moods.'),
(116, 'Enthusiasm', 'enthusiasm', 'green', 'uncommon', 0, NULL, 0, 1, 0, 'While in play, while scoring, you may score one of your moods an extra time.'),
(117, 'Euphoria', 'euphoria', 'green', 'rare', 0, NULL, 0, 1, 0, 'While in play, this mood''s value increases by 1 for each mood in play, including itself and other players'' moods.'),
(118, 'Fascination', 'fascination', 'green', 'uncommon', 3, 6, 0, 0, 1, 'After playing this mood, you may reveal a blue or black card from your hand and give it to another player. If you do, this mood''s value becomes 6.'),
(119, 'Fondness', 'fondness', 'green', 'uncommon', 0, 6, 0, 1, 0, 'While in play, this mood''s value is 6 if each player has three or more moods.'),
(120, 'Generosity', 'generosity', 'green', 'common', 6, NULL, 0, 0, 1, 'After playing this mood, choose an opponent. They may play an additional mood on their next turn.'),
(121, 'Grace', 'grace', 'green', 'rare', 0, NULL, 0, 1, 0, 'While in play, during each of your turns (including the turn you play this mood), you may play an additional mood from the discard pile if it shares a color with one of your moods.'),
(122, 'Happiness', 'happiness', 'green', 'uncommon', 2, 6, 0, 1, 0, 'While in play, this mood''s value is 6 if a player has both a red mood and a white mood.'),
(123, 'Harmony', 'harmony', 'green', 'uncommon', 2, NULL, 0, 0, 1, 'After playing this mood, you may play an additional mood this turn from the discard pile.'),
(124, 'Hope', 'hope', 'green', 'rare', 0, NULL, 0, 1, 0, 'While in play, you may play an additional mood during each of your turns, including the turn you play this mood.'),
(125, 'Joy', 'joy', 'green', 'common', 3, NULL, 0, 0, 1, 'After playing this mood, you may play an additional mood on your next turn.'),
(126, 'Laziness', 'laziness', 'green', 'common', 4, NULL, 0, 0, 0, 'No ability. One of five vanilla commons, one per color, each worth a flat 4.'),
(127, 'Love', 'love', 'green', 'mythic', 4, 6, 0, 1, 0, 'While in play, this mood''s value is 6 if there''s a white mood, a blue mood, a black mood, a red mood, and a green mood, including this one. (The Headliner treatment of this card is a mechanically identical alternate-art printing, not a separate card.)'),
(128, 'Nostalgia', 'nostalgia', 'green', 'common', 0, NULL, 0, 0, 1, 'After playing this mood, you may put a card from the discard pile into your hand. You may play an additional mood this turn.'),
(129, 'Serenity', 'serenity', 'green', 'uncommon', 3, 6, 0, 1, 0, 'While in play, this mood''s value is 6 if you have an even number of moods, including this one.'),
(130, 'Sloth', 'sloth', 'green', 'rare', 3, NULL, 0, 1, 0, 'While in play, this mood''s value increases by 1 for each card in your hand.'),
(131, 'Tranquility', 'tranquility', 'green', 'uncommon', 3, 6, 0, 1, 0, 'While in play, this mood''s value is 6 if you have an odd number of moods, including this one.'),
(132, 'Vulnerability', 'vulnerability', 'green', 'rare', 1, 6, 0, 1, 0, 'While in play, this mood''s value is 6 if a card was put into the discard pile this round.'),
(133, 'Wonder', 'wonder', 'green', 'mythic', 0, NULL, 0, 1, 1, 'After playing this mood, choose a color. While in play, this mood''s value increases by 2 for each mood of the chosen color and each card in the discard pile of the chosen color.');

-- ---------------------------------------------------------------------------
-- 0004_create_game_tables.sql
-- ---------------------------------------------------------------------------
-- Match-level tables for actually playing Mood Swings, layered on top of
-- the account/friends tables from earlier migrations and the card catalog
-- from 0003. A "game" is one played-to-completion session; "rounds" are
-- the repeated play-a-mood-then-score cycles within it (see the Extended
-- Rules document).

-- format covers the variants from "Other Ways to Play": 'standard' (the
-- base rules), 'duel' (2-player), and 'team' (uses game_players.team_id
-- to pair players up). Draft-style deck construction isn't modeled yet --
-- deferred until that format is actually implemented, since it changes
-- how a game's card pool is assembled rather than anything here.
CREATE TABLE IF NOT EXISTS games (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    format ENUM('standard', 'duel', 'team') NOT NULL DEFAULT 'standard',
    status ENUM('waiting', 'in_progress', 'completed', 'abandoned') NOT NULL DEFAULT 'waiting',
    created_by_user_id INT UNSIGNED NOT NULL,
    wins_needed TINYINT UNSIGNED NOT NULL DEFAULT 3,
    winner_game_player_id INT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    started_at TIMESTAMP NULL DEFAULT NULL,
    completed_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_games_created_by (created_by_user_id),
    CONSTRAINT fk_games_created_by FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per seated player in a game. seat_order is turn order (play
-- proceeds clockwise, i.e. in ascending seat_order, per the rules).
-- team_id only means anything for the 'team' format and pairs two players
-- together (see FriendshipRepository-style user pairing for a similar
-- idea, though teams aren't unordered pairs the way friendships are).
CREATE TABLE IF NOT EXISTS game_players (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    game_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    seat_order TINYINT UNSIGNED NOT NULL,
    team_id TINYINT UNSIGNED DEFAULT NULL,
    left_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_game_players_seat (game_id, seat_order),
    UNIQUE KEY uq_game_players_user (game_id, user_id),
    CONSTRAINT fk_game_players_game FOREIGN KEY (game_id) REFERENCES games (id) ON DELETE CASCADE,
    CONSTRAINT fk_game_players_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- games.winner_game_player_id can't be declared inline above since
-- game_players doesn't exist yet at that point.
ALTER TABLE games
    ADD CONSTRAINT fk_games_winner FOREIGN KEY (winner_game_player_id) REFERENCES game_players (id) ON DELETE SET NULL;

-- One row per round of play within a game. hurt_feelings_game_player_id is
-- deliberately modeled here as an attribute of the round rather than a row
-- in `cards`: physically Hurt Feelings is a marker/token, never a card
-- that's in a deck, hand, or discard pile, and who holds it is entirely
-- determined by the previous round's score (the lowest scorer gets it for
-- the next round; ties go to whoever took their turn latest). It's set
-- when a round begins and grants that player the ability to play an extra
-- mood on their turn that round.
CREATE TABLE IF NOT EXISTS game_rounds (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    game_id INT UNSIGNED NOT NULL,
    round_number SMALLINT UNSIGNED NOT NULL,
    first_game_player_id INT UNSIGNED NOT NULL,
    hurt_feelings_game_player_id INT UNSIGNED DEFAULT NULL,
    status ENUM('in_progress', 'scored') NOT NULL DEFAULT 'in_progress',
    winner_game_player_id INT UNSIGNED DEFAULT NULL,
    wins_awarded TINYINT UNSIGNED NOT NULL DEFAULT 1,
    started_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    scored_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_game_rounds_number (game_id, round_number),
    CONSTRAINT fk_game_rounds_game FOREIGN KEY (game_id) REFERENCES games (id) ON DELETE CASCADE,
    CONSTRAINT fk_game_rounds_first_player FOREIGN KEY (first_game_player_id) REFERENCES game_players (id) ON DELETE CASCADE,
    CONSTRAINT fk_game_rounds_hurt_feelings FOREIGN KEY (hurt_feelings_game_player_id) REFERENCES game_players (id) ON DELETE SET NULL,
    CONSTRAINT fk_game_rounds_winner FOREIGN KEY (winner_game_player_id) REFERENCES game_players (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Each player's computed score at the moment a round was scored. Mood
-- values are dynamic (most cards' values depend on the current board
-- state), so this snapshot -- not a live recomputation from game_cards --
-- is the historical record of what actually happened, even after the
-- moods that produced it move around or leave play. wins_awarded on
-- game_rounds (usually 1) covers effects like Corruption that let a
-- round's winner count for two rounds instead of one.
CREATE TABLE IF NOT EXISTS game_round_scores (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    game_round_id INT UNSIGNED NOT NULL,
    game_player_id INT UNSIGNED NOT NULL,
    score SMALLINT NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_game_round_scores_player (game_round_id, game_player_id),
    CONSTRAINT fk_game_round_scores_round FOREIGN KEY (game_round_id) REFERENCES game_rounds (id) ON DELETE CASCADE,
    CONSTRAINT fk_game_round_scores_player FOREIGN KEY (game_player_id) REFERENCES game_players (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Where every physical card in a game currently is. Each of the 133 cards
-- gets exactly one game_cards row per game (there's only one copy of each
-- in the shared deck), and this table is the single source of truth for
-- "where is this card right now" -- moving a card between zones is an
-- UPDATE of its row, not a move between separate per-zone tables.
--
-- copied_card_id supports Creativity: while in play "as a copy" of another
-- mood, card_id still identifies the physical Creativity card (so effects
-- that care what a card actually is -- e.g. copying a Creativity copies
-- whatever *it's* a copy of, not Creativity itself -- resolve correctly),
-- while copied_card_id says which card's rules text and value it's
-- currently using. NULL means it's not currently a copy of anything.
--
-- Suppression (several cards suppress a mood so it scores as 0 without
-- removing it from play) is tracked here rather than as a separate zone,
-- since a suppressed mood is still fully in play for every other purpose
-- (color counts, "moodiest opponent", etc. all still see it).
CREATE TABLE IF NOT EXISTS game_cards (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    game_id INT UNSIGNED NOT NULL,
    card_id SMALLINT UNSIGNED NOT NULL,
    zone ENUM('deck', 'discard', 'hand', 'in_play') NOT NULL,
    owner_game_player_id INT UNSIGNED DEFAULT NULL,
    deck_position SMALLINT UNSIGNED DEFAULT NULL,
    copied_card_id SMALLINT UNSIGNED DEFAULT NULL,
    is_suppressed TINYINT(1) NOT NULL DEFAULT 0,
    suppression_expiry ENUM('end_of_round', 'while_source_in_play') DEFAULT NULL,
    suppression_source_game_card_id INT UNSIGNED DEFAULT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_game_cards_card (game_id, card_id),
    KEY idx_game_cards_owner_zone (owner_game_player_id, zone),
    KEY idx_game_cards_zone (game_id, zone),
    CONSTRAINT fk_game_cards_game FOREIGN KEY (game_id) REFERENCES games (id) ON DELETE CASCADE,
    CONSTRAINT fk_game_cards_card FOREIGN KEY (card_id) REFERENCES cards (id),
    CONSTRAINT fk_game_cards_copied_card FOREIGN KEY (copied_card_id) REFERENCES cards (id),
    CONSTRAINT fk_game_cards_owner FOREIGN KEY (owner_game_player_id) REFERENCES game_players (id) ON DELETE CASCADE,
    CONSTRAINT fk_game_cards_suppression_source FOREIGN KEY (suppression_source_game_card_id) REFERENCES game_cards (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Append-only history of every action taken during a game, independent of
-- (and in addition to) the current-state tables above, so a game can be
-- studied or replayed turn-by-turn after the fact even once the board
-- state that produced it has moved on. `id` is a bigint so it can keep
-- growing across every game on the site, and doubles as the ordering key
-- within a game (alongside created_at) since two events in the same
-- request can otherwise land on the same timestamp.
--
-- event_type plus a JSON details payload is deliberate rather than a
-- column-per-effect design: with 133 distinct card effects, each with its
-- own choices, targets, and quantities, modeling every possible action as
-- dedicated columns isn't practical. details' shape is defined by
-- event_type (e.g. a 'mood_played' event's details might hold the chosen
-- color/number/targets for that card's effect).
CREATE TABLE IF NOT EXISTS game_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    game_id INT UNSIGNED NOT NULL,
    game_round_id INT UNSIGNED DEFAULT NULL,
    acting_game_player_id INT UNSIGNED DEFAULT NULL,
    event_type VARCHAR(40) NOT NULL,
    card_id SMALLINT UNSIGNED DEFAULT NULL,
    details JSON DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_game_events_game (game_id, id),
    KEY idx_game_events_round (game_round_id),
    CONSTRAINT fk_game_events_game FOREIGN KEY (game_id) REFERENCES games (id) ON DELETE CASCADE,
    CONSTRAINT fk_game_events_round FOREIGN KEY (game_round_id) REFERENCES game_rounds (id) ON DELETE CASCADE,
    CONSTRAINT fk_game_events_player FOREIGN KEY (acting_game_player_id) REFERENCES game_players (id) ON DELETE SET NULL,
    CONSTRAINT fk_game_events_card FOREIGN KEY (card_id) REFERENCES cards (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 0005_fix_card_alt_values.sql
-- ---------------------------------------------------------------------------
-- Fixes a data bug in the 0003 card catalog seed. The source material
-- represents card values 0-6 as a single die icon, but values 7-12 as
-- *two* die icons shown side by side that get added together (e.g. a "6"
-- die next to a "1" die means the value is 6+1=7 -- see the Extended
-- Rules' Vocabulary entry for "[0]-[6]": "Any number from 7 to 12 will be
-- depicted as two dice next to each other"). When the card catalog was
-- transcribed, these two-die values were misread as just their first die
-- (e.g. "6+1=7" got stored as 6), producing 10 cards with an incorrect
-- alt_value. This corrects both alt_value and the rules_text wording that
-- names the value.
UPDATE cards SET alt_value = 7, rules_text = 'After playing this mood, if the discard pile has at least one card in it, this mood''s value becomes 7. Then, starting with the next player in turn order, each player takes a random card from the discard pile and puts it into their hand. Put the rest of the discard pile onto the bottom of the deck in a random order.' WHERE id = 1;
UPDATE cards SET alt_value = 8, rules_text = 'While in play, this mood''s value is 8 if there are two or more cards in the discard pile that share a color.' WHERE id = 70;
UPDATE cards SET alt_value = 7, rules_text = 'While in play, this mood''s value is 7 if you have more moods than each other player.' WHERE id = 77;
UPDATE cards SET alt_value = 9, rules_text = 'After playing this mood, you may put two of your other moods into the discard pile. If you do, this mood''s value becomes 9.' WHERE id = 95;
UPDATE cards SET alt_value = 7, rules_text = 'While in play, this mood''s value is 7 if there are more colors among your moods than among each other player''s moods.' WHERE id = 109;
UPDATE cards SET alt_value = 7, rules_text = 'After playing this mood, you may reveal a blue or black card from your hand and give it to another player. If you do, this mood''s value becomes 7.' WHERE id = 118;
UPDATE cards SET alt_value = 7, rules_text = 'While in play, this mood''s value is 7 if each player has three or more moods.' WHERE id = 119;
UPDATE cards SET alt_value = 8, rules_text = 'While in play, this mood''s value is 8 if a player has both a red mood and a white mood.' WHERE id = 122;
UPDATE cards SET alt_value = 12, rules_text = 'While in play, this mood''s value is 12 if there''s a white mood, a blue mood, a black mood, a red mood, and a green mood, including this one. (The Headliner treatment of this card is a mechanically identical alternate-art printing, not a separate card.)' WHERE id = 127;
UPDATE cards SET alt_value = 7, rules_text = 'While in play, this mood''s value is 7 if a card was put into the discard pile this round.' WHERE id = 132;

-- ---------------------------------------------------------------------------
-- 0006_add_turn_state_to_game_rounds.sql
-- ---------------------------------------------------------------------------
-- Adds live turn state to game_rounds: whose turn it currently is, and how
-- many more moods they may still play this turn (starts at 1, or 2 if
-- they hold Hurt Feelings; incremented by cards like Charity). This has
-- to be persisted between requests -- a player's turn can span multiple
-- plays granted by "you may play an additional mood" effects, and each
-- play is its own request/response round trip, so there's no in-memory
-- process alive between them to remember it in.
ALTER TABLE game_rounds
    ADD COLUMN current_turn_game_player_id INT UNSIGNED DEFAULT NULL AFTER first_game_player_id,
    ADD COLUMN plays_remaining TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER current_turn_game_player_id;

ALTER TABLE game_rounds
    ADD CONSTRAINT fk_game_rounds_current_turn FOREIGN KEY (current_turn_game_player_id) REFERENCES game_players (id) ON DELETE SET NULL;

-- ---------------------------------------------------------------------------
-- 0007_add_effect_state_to_game_cards.sql
-- ---------------------------------------------------------------------------
-- Adds storage for a mood's per-card effect state: modal choices (e.g.
-- Imagination's chosen color) and one-time value overrides (e.g. Dignity's
-- "value becomes 5"), mirroring MoodInPlay::$effectState in the in-memory
-- rules engine (see src/Rules/MoodInPlay.php). This was missed when
-- game_cards was first created in 0004 -- everything else needed to
-- reconstruct a MoodInPlay already had a column, but this one didn't.
ALTER TABLE game_cards
    ADD COLUMN effect_state JSON DEFAULT NULL AFTER suppression_source_game_card_id;

-- ---------------------------------------------------------------------------
-- 0008_add_pending_play_grants_to_game_rounds.sql
-- ---------------------------------------------------------------------------
-- Adds storage for *restricted* extra-play grants: some cards (Benevolence,
-- Friendliness, Kindness, Eagerness) grant an additional play only usable
-- on a mood meeting some condition, rather than an unconditional extra
-- play like Charity's. plays_remaining's plain count can't express that on
-- its own -- this stores the actual list of outstanding grants (one JSON
-- object per grant, or null for an unconditional one), whose length is
-- kept in sync with plays_remaining by whoever writes it. See
-- BoardState::$playGrants / grantAllows() in the rules engine.
ALTER TABLE game_rounds
    ADD COLUMN pending_play_grants JSON DEFAULT NULL AFTER plays_remaining;

-- ---------------------------------------------------------------------------
-- 0009_add_discarded_this_round_to_game_rounds.sql
-- ---------------------------------------------------------------------------
-- Vulnerability: "While in play, this mood's value is 7 if a card was put
-- into the discard pile this round." This has to be a single flag on the
-- round itself, not something attached to any particular card's
-- effectState, since it needs to reflect *any* discard by *any* player,
-- including ones that happen before Vulnerability is even in play. Reset
-- to its default (false) automatically whenever a new round row is
-- inserted; set true by BoardState's discard-pile-adding methods and
-- persisted the same way pending_play_grants is, via GameService's
-- updateRoundTurnState(). See BoardState::discardedThisRound().
ALTER TABLE game_rounds
    ADD COLUMN discarded_this_round TINYINT(1) NOT NULL DEFAULT 0 AFTER pending_play_grants;

-- ---------------------------------------------------------------------------
-- 0010_add_pending_decision_tables.sql
-- ---------------------------------------------------------------------------
-- Support for cards whose real rules text gives the decision to a player
-- OTHER than the one whose turn it is (e.g. Compulsion: "That player
-- chooses a card from their hand and gives it to you") -- previously
-- approximated with a random pick since there was no way for a second,
-- different logged-in player to inject an answer mid-play. A play that
-- needs one of these now pauses instead of completing: the card has
-- already been moved into play, its cost already paid, its play grant
-- already consumed (see MoodPlayService::playMood()) -- only the
-- after-playing resolution itself is on hold, waiting on the targeted
-- player(s)' own response via a later, separate request.
--
-- A "batch" is one afterPlaying() invocation's whole decision -- either
-- the played card's own, or one of its Duplicity repeats (invocation_seq
-- 1+). It carries BOTH the original top-level PlayerChoices (needed later
-- for the reactToAnotherPlay() reaction loop, which per MoodEffect's own
-- contract always reads the top-level choices, never an invocation's own)
-- and this invocation's own choices (needed by the card's own
-- resolveDecisions()) -- they differ once a Duplicity repeat is involved,
-- where the invocation's own choices are the duplicity_repeat_choices
-- sub-bag rather than the top-level request.
--
-- A round has at most one open batch at a time (the whole round is frozen
-- while one is outstanding -- see GameService::playMood()/pass()), and a
-- batch can contain several individual decisions (one row each) for cards
-- that ask more than one player something -- e.g. Disillusionment asks
-- every other player, in turn order, one at a time; Suspicion asks each
-- of however many players were chosen. Only the lowest-step_index
-- unresolved row in the open batch is ever actively prompted.
CREATE TABLE IF NOT EXISTS game_pending_decision_batches (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    game_id INT UNSIGNED NOT NULL,
    game_round_id INT UNSIGNED NOT NULL,
    played_card_id SMALLINT UNSIGNED NOT NULL,
    invocation_seq TINYINT UNSIGNED NOT NULL DEFAULT 0,
    initiating_game_player_id INT UNSIGNED NOT NULL,
    top_level_choices JSON NOT NULL,
    invocation_choices JSON NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resolved_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_pending_batches_round_open (game_round_id, resolved_at),
    CONSTRAINT fk_pending_batches_game FOREIGN KEY (game_id) REFERENCES games (id) ON DELETE CASCADE,
    CONSTRAINT fk_pending_batches_round FOREIGN KEY (game_round_id) REFERENCES game_rounds (id) ON DELETE CASCADE,
    CONSTRAINT fk_pending_batches_card FOREIGN KEY (played_card_id) REFERENCES cards (id),
    CONSTRAINT fk_pending_batches_initiator FOREIGN KEY (initiating_game_player_id) REFERENCES game_players (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- field is the CardChoiceSchema-shaped prompt to show target_game_player_id
-- (type/scope/filter/multi/count, same vocabulary GET /games/state already
-- uses for choice_fields -- see CardChoiceSchema.php's docblock), computed
-- once at batch-creation time from that target's own perspective (e.g.
-- Compulsion's field lists the target's own hand -- no candidate list
-- needs to be embedded, the client already renders an unfiltered
-- hand_card field from the target's own GET /games/state response the
-- same way it does everywhere else). answer is filled in once the target
-- responds. ON DELETE RESTRICT on target_game_player_id for the same
-- reason as initiating_game_player_id above: nothing can delete a seated
-- game_players row today (no "leave game" feature exists), but if one is
-- ever added it must not be allowed to silently vanish an outstanding
-- decision and unfreeze the round with the original card's effect never
-- actually resolved.
CREATE TABLE IF NOT EXISTS game_pending_decisions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    batch_id BIGINT UNSIGNED NOT NULL,
    step_index TINYINT UNSIGNED NOT NULL,
    target_game_player_id INT UNSIGNED NOT NULL,
    decision_type VARCHAR(40) NOT NULL,
    field JSON NOT NULL,
    answer JSON DEFAULT NULL,
    resolved_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_pending_decisions_step (batch_id, step_index),
    KEY idx_pending_decisions_target_open (target_game_player_id, resolved_at),
    CONSTRAINT fk_pending_decisions_batch FOREIGN KEY (batch_id) REFERENCES game_pending_decision_batches (id) ON DELETE CASCADE,
    CONSTRAINT fk_pending_decisions_target FOREIGN KEY (target_game_player_id) REFERENCES game_players (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 0011_prevent_concurrent_pending_decision_batches.sql
-- ---------------------------------------------------------------------------
-- Closes the race GameService::assertNoPendingDecision() couldn't close on
-- its own: it's a plain SELECT-then-INSERT, so two requests for the same
-- round (e.g. the same player's two open tabs, or a play racing a
-- respondToDecision() that itself uncovers a chained pending decision) can
-- both see "no pending decision" and both go on to create their own batch,
-- leaving two simultaneously-open batches for one round -- something the
-- rest of the code (which always assumes at most one) never expects and
-- has no way to recover from.
--
-- active_marker is NULL for every resolved batch (resolved_at IS NOT NULL)
-- and a constant 1 for the one batch, if any, still open -- a UNIQUE index
-- treats each NULL as distinct, so any number of resolved batches share a
-- round without conflict, but a second INSERT for a round that already has
-- an open batch collides on (game_round_id, active_marker) and fails
-- immediately, at the database level, rather than silently succeeding.
-- GameService::writePendingBatch() catches that specific duplicate-key
-- error and turns it into the same GameStateException
-- assertNoPendingDecision() already throws for the non-racing case.
ALTER TABLE game_pending_decision_batches
    ADD COLUMN active_marker TINYINT UNSIGNED GENERATED ALWAYS AS (IF(resolved_at IS NULL, 1, NULL)) STORED AFTER resolved_at,
    ADD UNIQUE KEY uq_pending_batches_one_open_per_round (game_round_id, active_marker);

-- ---------------------------------------------------------------------------
-- 0012_add_deck_type_to_games.sql
-- ---------------------------------------------------------------------------
-- Until now every game used the same 133-card pool -- one copy of every
-- printed card, assembled by GameService::startGame() as range(1, 133).
-- deck_type introduces a second option: 'standard' (a randomly-assembled
-- 45-card singleton deck matching a new box's own printed rarity
-- distribution -- 23 common/14 uncommon/6 rare/2 mythic) alongside the
-- existing pool, renamed here 'one_of_each' for clarity now that it's one
-- of two choices rather than the only one. 'standard' is the default,
-- matching how a new physical box is actually played out of the box --
-- 'one_of_each' is the opt-in for the fuller, higher-variance card pool.
-- Chosen once at createGame() time (like format) and read by startGame()
-- when the deck is actually assembled; nothing about which cards a
-- particular game ends up with is decided until then, matching how the
-- format/wins_needed choices already work.
ALTER TABLE games
    ADD COLUMN deck_type ENUM('standard', 'one_of_each') NOT NULL DEFAULT 'standard' AFTER format;

-- ---------------------------------------------------------------------------
-- 0013_add_instance_card_identity.sql
-- ---------------------------------------------------------------------------
-- Duel mode gives each player their own complete deck (built by the same
-- deck-building rules as a normal single-player deck) rather than splitting
-- one shared pool, so the same catalog card can now legitimately exist
-- twice in one game -- once per player. game_cards.id (the surrogate PK
-- that already existed solely to resolve suppression_source_game_card_id's
-- self-reference) becomes every card's real per-game identity instead of
-- card_id (the catalog id), which uq_game_cards_card previously enforced
-- as unique per game. See BoardState::catalogRow()/$catalogCardIdFor.
ALTER TABLE game_cards
    DROP INDEX uq_game_cards_card,
    ADD KEY idx_game_cards_card (card_id);

-- copied_card_id stores the copied mood's own per-game instance id (the
-- 'copy_card_id' choice names an in-play card the same way every other
-- choice does -- see BoardState::effectiveCardId()), not a catalog id as
-- originally modeled. Repointed self-referentially, same pattern as
-- suppression_source_game_card_id, and widened from SMALLINT to match
-- game_cards.id's own INT UNSIGNED width. Split into three separate
-- statements (rather than one combined ALTER): DROP FOREIGN KEY doesn't
-- drop its supporting index, so re-adding a same-named constraint in the
-- same statement collides with that leftover index (errno 121); MODIFY
-- COLUMN also can't run while the old FK still references the column, and
-- the new FK can't be added until the column's width already matches
-- game_cards.id's.
ALTER TABLE game_cards
    DROP FOREIGN KEY fk_game_cards_copied_card;
ALTER TABLE game_cards
    MODIFY COLUMN copied_card_id INT UNSIGNED DEFAULT NULL;
ALTER TABLE game_cards
    ADD CONSTRAINT fk_game_cards_copied_card FOREIGN KEY (copied_card_id) REFERENCES game_cards (id) ON DELETE SET NULL;

-- played_card_id/card_id below store whatever $cardId GameService had in
-- hand at the time, now always a game_cards.id -- repointed + widened from
-- SMALLINT UNSIGNED (max 65535, far too narrow for game_cards.id's range
-- across every game on the site) to match. Same three-statement split as
-- above, for the same reasons.
ALTER TABLE game_pending_decision_batches
    DROP FOREIGN KEY fk_pending_batches_card;
ALTER TABLE game_pending_decision_batches
    MODIFY COLUMN played_card_id INT UNSIGNED NOT NULL;
ALTER TABLE game_pending_decision_batches
    ADD CONSTRAINT fk_pending_batches_card FOREIGN KEY (played_card_id) REFERENCES game_cards (id) ON DELETE CASCADE;

ALTER TABLE game_events
    DROP FOREIGN KEY fk_game_events_card;
ALTER TABLE game_events
    MODIFY COLUMN card_id INT UNSIGNED DEFAULT NULL;
ALTER TABLE game_events
    ADD CONSTRAINT fk_game_events_card FOREIGN KEY (card_id) REFERENCES game_cards (id) ON DELETE CASCADE;

-- ---------------------------------------------------------------------------
-- 0014_rename_standard_deck_type_and_add_power.sql
-- ---------------------------------------------------------------------------
-- deck_type's 'standard' value is renamed to 'structure' (a clearer name
-- now that a second small-deck option, 'power', exists alongside it), and
-- 'power' is added: a single random Mythic plus 14 other random non-Mythic
-- cards (15 total) -- a small, mythic-guaranteed deck for a faster,
-- higher-power game, distinct from 'structure''s own printed rarity mix
-- (23 common/14 uncommon/6 rare/2 mythic, 45 total) -- see
-- GameService::buildStructureDeckCardIds()/buildPowerDeckCardIds().
--
-- Enum values can't be renamed in place while rows still reference the old
-- one, so this widens the enum first, migrates existing rows, then narrows
-- it to the final set with the new default.
ALTER TABLE games
    MODIFY COLUMN deck_type ENUM('standard', 'structure', 'power', 'one_of_each') NOT NULL DEFAULT 'standard';

UPDATE games SET deck_type = 'structure' WHERE deck_type = 'standard';

ALTER TABLE games
    MODIFY COLUMN deck_type ENUM('structure', 'power', 'one_of_each') NOT NULL DEFAULT 'structure';

-- ---------------------------------------------------------------------------
-- 0015_add_card_sets.sql
-- ---------------------------------------------------------------------------
-- Every card belongs to at least one Set (a printed product, identified by
-- a short set code the way physical/digital card games label packs -- e.g.
-- Magic's "war of the spark" is "WAR"). The only Set that exists today is
-- the base game itself, "Mood Swings" (MSW), which all 133 existing cards
-- belong to.
--
-- This is a many-to-many join (card_sets), not a set_id column directly on
-- cards, because a card reappearing in a later set (a reprint, a
-- crossover/anniversary product, etc.) is expected to eventually happen
-- even though it's not possible with only one Set defined yet -- a single
-- FK column would have to be widened into a join table the moment that
-- happened anyway, so it's modeled that way from the start.
CREATE TABLE IF NOT EXISTS sets (
    id TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code VARCHAR(10) NOT NULL,
    name VARCHAR(60) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sets_code (code),
    UNIQUE KEY uq_sets_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO sets (code, name) VALUES ('MSW', 'Mood Swings');

CREATE TABLE IF NOT EXISTS card_sets (
    card_id SMALLINT UNSIGNED NOT NULL,
    set_id TINYINT UNSIGNED NOT NULL,
    PRIMARY KEY (card_id, set_id),
    CONSTRAINT fk_card_sets_card FOREIGN KEY (card_id) REFERENCES cards (id) ON DELETE CASCADE,
    CONSTRAINT fk_card_sets_set FOREIGN KEY (set_id) REFERENCES sets (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO card_sets (card_id, set_id)
SELECT c.id, s.id FROM cards c, sets s WHERE s.code = 'MSW';

-- ---------------------------------------------------------------------------
-- 0016_add_jceddys_75_deck_type.sql
-- ---------------------------------------------------------------------------
-- Adds a fourth deck_type: "jceddy's 75 Card" -- per color, 1 random
-- Mythic, 2 different random Rares, 4 random Uncommons (up to 2 copies of
-- any single Uncommon), and 8 random Commons (up to 3 copies of any single
-- Common) -- 15 cards per color, 75 total across White/Blue/Black/Red/
-- Green. Purely additive (no existing rows reference a value being
-- renamed), so this only needs to widen the enum, unlike 0014's
-- widen/migrate/narrow for an actual rename.
ALTER TABLE games
    MODIFY COLUMN deck_type ENUM('structure', 'power', 'jceddys_75', 'one_of_each') NOT NULL DEFAULT 'structure';

-- ---------------------------------------------------------------------------
-- 0017_add_last_move_at_to_games.sql
-- ---------------------------------------------------------------------------
-- games already tracks Created Time (created_at) and Started Time
-- (started_at, set by GameService::startGame()) and Completed Time
-- (completed_at, set once the game's winner is decided) -- this adds the
-- fourth: Last Move Time, set by GameService::touchLastMoveAt() after
-- every successful playMood()/pass()/respondToDecision() call, so the
-- lobby can tell a stalled game apart from an actively-progressing one
-- and sort by recent activity rather than just when the game began.
ALTER TABLE games
    ADD COLUMN last_move_at TIMESTAMP NULL DEFAULT NULL AFTER started_at;

-- ---------------------------------------------------------------------------
-- 0018_add_custom_decklist_to_games.sql
-- ---------------------------------------------------------------------------
-- A fifth deck_type, 'custom': the creator supplies their own decklist
-- (uploaded as a text file or pasted into a form field) instead of one of
-- the algorithmically-assembled pools. Only supported for Traditional
-- (non-'duel') games -- GameService::createGame() rejects the combination
-- server-side.
--
-- custom_deck_name is the decklist's own optional "Name" metadata field
-- (see DecklistParser); NULL when the decklist didn't specify one, in
-- which case the client shows "Uploaded Deck" instead. custom_deck_card_ids
-- is the fully-resolved list of catalog card ids (one entry per copy,
-- already expanded -- e.g. "3 Charity" contributes three 3's), parsed and
-- validated once at createGame() time rather than re-parsed at
-- startGame() time, so a decklist error surfaces immediately rather than
-- only once the game is started.
ALTER TABLE games
    MODIFY COLUMN deck_type ENUM('structure', 'power', 'jceddys_75', 'custom', 'one_of_each') NOT NULL DEFAULT 'structure',
    ADD COLUMN custom_deck_name VARCHAR(120) DEFAULT NULL AFTER deck_type,
    ADD COLUMN custom_deck_card_ids JSON DEFAULT NULL AFTER custom_deck_name;

-- ---------------------------------------------------------------------------
-- 0019_add_custom_duel_decks.sql
-- ---------------------------------------------------------------------------
-- A sixth deck_type, 'custom_duel': for Duel games only, each of the two
-- players supplies their own decklist (same file/paste format as the
-- 'custom' deck_type -- see DecklistParser) rather than sharing one deck
-- or drawing from an algorithmically-assembled pool. Unlike 'custom',
-- nothing is parsed at createGame() time -- the creator instead defines
-- the deck-building RULES both players' own decklists must satisfy (see
-- DuelDeckRules), and each player submits their own decklist afterward,
-- while the game is still 'waiting', via GameService::submitCustomDuelDeck().
--
-- custom_duel_rules_preset records which preset (if any) the creator
-- picked -- 'structure'/'power'/'jceddys_75' lock the rule values to
-- match those deck types' own generators (see DuelDeckRules::forPreset()),
-- 'user_defined' means the creator picked the three rule values
-- themselves. Only meaningful when deck_type = 'custom_duel'.
--
-- custom_duel_min_cards/rarity_limits/duplicate_limits are the resolved
-- rule values themselves (already resolved from the preset, if one was
-- used, at createGame() time) -- rarity_limits and duplicate_limits are
-- both `{rarity: count}` maps that may omit any rarity, meaning "no
-- restriction for that rarity" (see DuelDeckRules).
ALTER TABLE games
    MODIFY COLUMN deck_type ENUM('structure', 'power', 'jceddys_75', 'custom', 'custom_duel', 'one_of_each') NOT NULL DEFAULT 'structure',
    ADD COLUMN custom_duel_rules_preset ENUM('structure', 'power', 'jceddys_75', 'user_defined') DEFAULT NULL AFTER custom_deck_card_ids,
    ADD COLUMN custom_duel_min_cards SMALLINT UNSIGNED DEFAULT NULL AFTER custom_duel_rules_preset,
    ADD COLUMN custom_duel_rarity_limits JSON DEFAULT NULL AFTER custom_duel_min_cards,
    ADD COLUMN custom_duel_duplicate_limits JSON DEFAULT NULL AFTER custom_duel_rarity_limits;

-- Each duel player's own submitted decklist, once GameService::
-- submitCustomDuelDeck() validates it against the game's own
-- custom_duel_* rules above -- mirrors games.custom_deck_name/
-- custom_deck_card_ids, just scoped to one seat instead of the whole
-- table. NULL until that player submits; startGame() refuses to deal for
-- a 'custom_duel' game until every seat's custom_deck_card_ids is set.
ALTER TABLE game_players
    ADD COLUMN custom_deck_name VARCHAR(120) DEFAULT NULL AFTER team_id,
    ADD COLUMN custom_deck_card_ids JSON DEFAULT NULL AFTER custom_deck_name;

-- ---------------------------------------------------------------------------
-- 0020_add_duel_even_color_distribution_rule.sql
-- ---------------------------------------------------------------------------
-- An optional fourth 'custom_duel' deck-building rule (see DuelDeckRules):
-- a per-rarity flag requiring that rarity's cards be split evenly across
-- all 5 colors (e.g. 5 mythic total => exactly 1 of each color). Stored
-- as the JSON array of rarity names the flag is set for (a missing
-- rarity means no such requirement), same shape as
-- custom_duel_rarity_limits/custom_duel_duplicate_limits are `{rarity:
-- count}` maps for their own two rules. Locked to all four rarities for
-- the 'jceddys_75' rules preset, matching that generator's own "N per
-- color, for every color" guarantee.
ALTER TABLE games
    ADD COLUMN custom_duel_even_color_distribution_rarities JSON DEFAULT NULL AFTER custom_duel_duplicate_limits;

-- ---------------------------------------------------------------------------
-- Record migrations 0001-0020 as already-applied, so `composer migrate`
-- (see php-app/bin/migrate.php) skips straight to 0021+ from here rather
-- than trying to re-run any of the above.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS schema_migrations (
    migration VARCHAR(255) NOT NULL,
    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (migration)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO schema_migrations (migration) VALUES
    ('0001_baseline.sql'),
    ('0002_create_friendships_table.sql'),
    ('0003_create_card_catalog.sql'),
    ('0004_create_game_tables.sql'),
    ('0005_fix_card_alt_values.sql'),
    ('0006_add_turn_state_to_game_rounds.sql'),
    ('0007_add_effect_state_to_game_cards.sql'),
    ('0008_add_pending_play_grants_to_game_rounds.sql'),
    ('0009_add_discarded_this_round_to_game_rounds.sql'),
    ('0010_add_pending_decision_tables.sql'),
    ('0011_prevent_concurrent_pending_decision_batches.sql'),
    ('0012_add_deck_type_to_games.sql'),
    ('0013_add_instance_card_identity.sql'),
    ('0014_rename_standard_deck_type_and_add_power.sql'),
    ('0015_add_card_sets.sql'),
    ('0016_add_jceddys_75_deck_type.sql'),
    ('0017_add_last_move_at_to_games.sql'),
    ('0018_add_custom_decklist_to_games.sql'),
    ('0019_add_custom_duel_decks.sql'),
    ('0020_add_duel_even_color_distribution_rule.sql');
