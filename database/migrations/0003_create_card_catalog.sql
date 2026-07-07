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
