-- Chaos Draft (issue #405), stage 1: schema for the effect pool, the five
-- token cards a handful of effects conjure, and the per-round choice/attach
-- mechanic. This is schema only -- no game logic reads any of it yet
-- (GameService::createGame() doesn't accept 'chaos_draft' as a deck_type
-- until a later migration finishes the mechanic), so this is safe to apply
-- ahead of the code that uses it, the same way 0125's own
-- draft_rotisserie_state table predated its full Rotisserie Draft rollout.
--
-- ## The effect pool: chaos_effects
--
-- 133 curated "after playing"/"while in play" effects (never a "to play"
-- cost -- see the issue's own scoping), each carrying a rarity the same
-- way a printed card does, confirmed by the maintainer via a spreadsheet.
-- Mirrors the `cards` table's own shape (rarity ENUM, rules_text as the
-- literal source-of-truth spec text a future implementation reads
-- against) rather than reusing `cards` itself -- a chaos effect isn't a
-- card (it has no name, color, or printed value of its own; it only ever
-- exists ATTACHED to a real card, modifying that card's own behavior, see
-- game_cards.chaos_effect_id below), so folding it into the same table
-- would mean a pile of always-NULL card-only columns on 133 new rows.
--
-- effect_key here is a plain sequential slug (chaos_001..chaos_133, in
-- the maintainer's own spreadsheet order) rather than a descriptive name
-- like `cards.effect_key` uses -- these effects are anonymous ("a
-- randomly generated effect" per the issue, no in-fiction name of their
-- own the way a printed card has), so a descriptive slug would just be
-- inventing a name nothing else in the game ever surfaces. The game
-- engine's own per-effect implementation (a later migration/PR, see the
-- issue's own "effect pool" open question) dispatches off this same key.
--
-- shape records which of the two ability timings the issue scoped
-- ("never a 'to play' cost") the effect uses -- 'after_playing' (88 of
-- 133) or 'while_in_play' (45 of 133) -- so a future ChaosEffectRegistry
-- can validate an effect actually implements the hook it claims to,
-- rather than trusting rules_text's own prose.
CREATE TABLE IF NOT EXISTS chaos_effects (
    id SMALLINT UNSIGNED NOT NULL,
    effect_key VARCHAR(40) NOT NULL,
    rarity ENUM('common', 'uncommon', 'rare', 'mythic') NOT NULL,
    shape ENUM('after_playing', 'while_in_play') NOT NULL,
    rules_text TEXT NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_chaos_effects_effect_key (effect_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO chaos_effects (id, effect_key, rarity, shape, rules_text) VALUES
(1, 'chaos_001', 'rare', 'after_playing', 'After playing this mood — If the discard pile has at least one card in it, this mood''s value becomes 7. Then starting with the next player in turn order, each player takes a random card from the discard pile and puts it into their hand. Put the rest of the discard pile onto the bottom of the deck in a random order.'),
(2, 'chaos_002', 'uncommon', 'after_playing', 'After playing this mood — You may play an additional mood this turn if it doesn''t share a color with any of your moods.'),
(3, 'chaos_003', 'common', 'after_playing', 'After playing this mood — You may play an additional mood this turn.'),
(4, 'chaos_004', 'common', 'while_in_play', 'While in play — This mood''s value is 5 if you didn''t go first this round.'),
(5, 'chaos_005', 'common', 'after_playing', 'After playing this mood - Put a white mood with value 1 named Smugness into play.'),
(6, 'chaos_006', 'uncommon', 'after_playing', 'After playing this mood — Choose a mood. Its player puts it on the bottom of the deck and draws a card.'),
(7, 'chaos_007', 'common', 'after_playing', 'After playing this mood — Choose up to two players. For each chosen player, put one of their moods with a value of 5 or more into the discard pile.'),
(8, 'chaos_008', 'common', 'after_playing', 'After playing this mood — You may discard a card from your hand with a  0, 1, 2, or 3 in its top right corner. If you do, this mood''s value becomes 5.'),
(9, 'chaos_009', 'common', 'while_in_play', 'While in play — This mood''s value is 3 if there are two or more black and/or red moods.'),
(10, 'chaos_010', 'rare', 'after_playing', 'After playing this mood — Starting with the next player in turn order, each player may choose a color. Put each other mood that shares one of those colors into the discard pile.'),
(11, 'chaos_011', 'uncommon', 'while_in_play', 'While in play - If this mood moves from play to the discard pile, put two white moods with value 1 named Smugness into play.'),
(12, 'chaos_012', 'uncommon', 'after_playing', 'After playing this mood — You may discard a green or blue card from your hand. If you do, suppress any mood. It remains suppressed for as long as you have this mood.'),
(13, 'chaos_013', 'uncommon', 'after_playing', 'After playing this mood — You may play an additional mood this turn if it has a 0, 2, 4, or 6 in its top right corner.'),
(14, 'chaos_014', 'uncommon', 'after_playing', 'After playing this mood — You may choose one:
 - Suppress a black or red mood. It remains suppressed for as long as you have this mood.
 - Suppress all black and red moods. Those moods remain suppressed for as long as you have this mood.'),
(15, 'chaos_015', 'rare', 'while_in_play', 'While in play - At the beginning of each round, if you go first this round, put a white mood with value 1 named Smugness into play.'),
(16, 'chaos_016', 'mythic', 'while_in_play', 'While in play - Each time a mood with dice in its lower left corner enters play, put a white mood with value 1 named Smugness into play.'),
(17, 'chaos_017', 'uncommon', 'after_playing', 'After playing this mood — You may play an additional mood this turn if it has a 1, 3, or 5 in its top right corner.'),
(18, 'chaos_018', 'common', 'while_in_play', 'While in play — This mood''s value is 6 if there are two or more green and/or blue moods.'),
(19, 'chaos_019', 'rare', 'after_playing', 'After playing this mood — Suppress all moods with a value of 5 or more. Those moods remain suppressed for as long as you have this mood.'),
(20, 'chaos_020', 'common', 'after_playing', 'After playing this mood — Choose up to two players. For each chosen player, suppress one of their moods. It remains suppressed for as long as you have this mood.'),
(21, 'chaos_021', 'common', 'while_in_play', 'While in play — This mood''s value is 1 if you played it this round.'),
(22, 'chaos_022', 'rare', 'after_playing', 'After playing this mood — You may choose a player with more moods than you (moods are cards in play). If you do, you may keep playing additional moods this turn until you have as many moods as the chosen player.'),
(23, 'chaos_023', 'uncommon', 'after_playing', 'After playing this mood — You may choose a number. If you do, suppress all other moods with the chosen value. They remain suppressed until the end of this round.'),
(24, 'chaos_024', 'mythic', 'while_in_play', 'While in play - Each time a mood becomes suppressed, put a white mood with value 1 named Smugness into play.'),
(25, 'chaos_025', 'rare', 'after_playing', 'After playing this mood — You may discard a card from your hand. If you do, suppress all other moods that share a color with the discarded card. Those moods remain suppressed for as long as you have this mood.'),
(26, 'chaos_026', 'mythic', 'while_in_play', 'While in play - Each time you play another mood with a 0 or 1 in its top right corner, put a white mood with value 1 named Smugness into play.'),
(27, 'chaos_027', 'common', 'while_in_play', 'While in play — This mood''s value is 3 if there are two or more red and/or green moods.'),
(28, 'chaos_028', 'common', 'after_playing', 'After playing this mood — Choose up to two players. For each chosen player, put one of their moods with an odd value into their hand.'),
(29, 'chaos_029', 'rare', 'after_playing', 'After playing this mood — Choose left or right. Each player chooses one of their moods and gives it to the next player in the chosen direction.'),
(30, 'chaos_030', 'common', 'after_playing', 'After playing this mood — After scoring this round, if you won the round, put this mood on the bottom of the deck and draw a card.'),
(31, 'chaos_031', 'uncommon', 'after_playing', 'After playing this mood — Choose left or right. Each player chooses a card from their hand and gives it to the next player in the chosen direction.'),
(32, 'chaos_032', 'rare', 'after_playing', 'After playing this mood - Draw a card.'),
(33, 'chaos_033', 'common', 'after_playing', 'After playing this mood — You may choose a player. If you do, that player reveals a random card from their hand. If the revealed card shares a color with any mood, this mood''s value becomes 6.'),
(34, 'chaos_034', 'rare', 'after_playing', 'After playing this mood — You may choose two other moods. If the two chosen moods share a color or have the same value, put them into their players'' hands.'),
(35, 'chaos_035', 'rare', 'after_playing', 'After playing this mood — You may choose a number. If you do, put all other moods with the chosen value into their players'' hands.'),
(36, 'chaos_036', 'uncommon', 'after_playing', 'After playing this mood — You may reveal any number of cards from your hand and put them on the bottom of the deck, then draw that many cards. During the next round, players can''t play moods that share a color with any of the revealed cards.'),
(37, 'chaos_037', 'mythic', 'while_in_play', 'While in play - Each time you play another mood, draw a card.'),
(38, 'chaos_038', 'common', 'after_playing', 'After playing this mood — You may put another one of your moods into your hand. You may play an additional mood this turn.'),
(39, 'chaos_039', 'uncommon', 'after_playing', 'After playing this mood — Calculate the most common color or colors among all moods. Put all moods other than this one that share one of those colors into their players'' hands.'),
(40, 'chaos_040', 'mythic', 'while_in_play', 'While in play - Each time an opponent plays a mood, draw a card.'),
(41, 'chaos_041', 'common', 'after_playing', 'After playing this mood — You may choose one:
 - Put a red or green mood into its player''s hand.
 - Put all red and green moods into their players'' hands.'),
(42, 'chaos_042', 'uncommon', 'after_playing', 'After playing this mood - Reveal the top card of your deck. If it matches the color of one of your moods, put it into your hand.'),
(43, 'chaos_043', 'uncommon', 'after_playing', 'After playing this mood — Choose any number of opponents who each have two or more moods. Each chosen player puts a random one of their moods into their hand.'),
(44, 'chaos_044', 'common', 'after_playing', 'After playing this mood - Put a blue mood with value 1 named Unconcern into play.'),
(45, 'chaos_045', 'uncommon', 'after_playing', 'After playing this mood — You may play an additional mood this turn. If you do, after scoring, put that mood into your hand (if it''s still in play).'),
(46, 'chaos_046', 'common', 'after_playing', 'After playing this mood - You may put one of your moods into your hand. If you do, look at the top card of your deck - you may put that card on the bottom of your deck.'),
(47, 'chaos_047', 'common', 'while_in_play', 'While in play — This mood''s value is 6 if there are two or more white and/or black moods.'),
(48, 'chaos_048', 'common', 'after_playing', 'After playing this mood — Choose up to two players. For each chosen player, put one of their moods into their hand. You can''t put this mood into your hand this way.'),
(49, 'chaos_049', 'rare', 'after_playing', 'After playing this mood — You may choose one:
 - Put your hand on the bottom of the deck, then draw that many cards.
 - Choose left or right. Simultaneously, each player gives their hand to the next player in the chosen direction.'),
(50, 'chaos_050', 'rare', 'after_playing', 'After playing this mood - You may choose an opponent''s mood. If you do, reveal the top card of your deck - if that card''s color matches the chosen mood''s color, put the chosen mood into your hand, otherwise put that mood into your opponent''s hand and draw a card.'),
(51, 'chaos_051', 'mythic', 'after_playing', 'After playing this mood — Choose an opponent. This round, after scoring, swap your score with that player before determining who wins the round.'),
(52, 'chaos_052', 'uncommon', 'after_playing', 'After playing this mood — You may put one of your white or black moods into your hand. If you do, put up to two moods other than this one each with a value of 3 or less into their players'' hands.'),
(53, 'chaos_053', 'common', 'after_playing', 'After playing this mood — You may discard a card from your hand. If you do, you may play an additional mood this turn.'),
(54, 'chaos_054', 'uncommon', 'after_playing', 'After playing this mood — You may put one of your blue or red moods into the discard pile. If you do, you may play an additional mood this turn from the discard pile. (You can play the card you just put into the discard pile if you want.)'),
(55, 'chaos_055', 'common', 'after_playing', 'After playing this mood - Put a black mood with value 1 named Passivity into play.'),
(56, 'chaos_056', 'uncommon', 'after_playing', 'After playing this mood - You may choose an opponent''s mood. If you do, permanently reduce the chosen mood''s value by this mood''s value. If the chosen mood''s value would become less than 0, put it in the discard pile instead.'),
(57, 'chaos_057', 'uncommon', 'after_playing', 'After playing this mood — Calculate the most common color or colors among all moods. Put all other moods that share one of those colors into the discard pile.'),
(58, 'chaos_058', 'common', 'after_playing', 'After playing this mood — You may give a card from your hand to another player. If you do, this mood''s value becomes 6.'),
(59, 'chaos_059', 'uncommon', 'after_playing', 'After playing this mood — You may choose one:
 - Put a green or white mood into the discard pile.
 - Put all green and white moods into the discard pile.'),
(60, 'chaos_060', 'rare', 'after_playing', 'After playing this mood — You may choose one:
 - Put up to two cards from the discard pile on the bottom of the deck, then draw that many cards.
 - The winner of the current round wins two rounds instead of one. (Each losing player still draws only one card.)'),
(61, 'chaos_061', 'uncommon', 'after_playing', 'After playing this mood — Choose any number of opponents who each have two or more moods. Each chosen opponent puts a random one of their moods into the discard pile. (Moods are cards in play.)'),
(62, 'chaos_062', 'uncommon', 'after_playing', 'After playing this mood — You may put a card from the discard pile into an opponent''s hand. If you do, this mood''s value becomes 6.'),
(63, 'chaos_063', 'common', 'while_in_play', 'While in play — This mood''s value is 3 if there are two or more green and/or white moods.'),
(64, 'chaos_064', 'rare', 'while_in_play', 'While in play - Each time a mood is put into the discard pile, you may choose an opponent''s mood and permanently reduce its value by 1. If this chosen mood''s value would become less than 0, put it in the discard pile instead.'),
(65, 'chaos_065', 'rare', 'after_playing', 'After playing this mood — You may play up to two additional cards this turn from the discard pile.'),
(66, 'chaos_066', 'common', 'after_playing', 'After playing this mood — You may put any mood on the bottom of the deck. If you do, draw a card. (Moods are cards in play.)'),
(67, 'chaos_067', 'rare', 'after_playing', 'After playing this mood — You may choose another player. If you do, that player reveals a card from their hand and puts it into your hand. You may play it as an additional mood this turn.'),
(68, 'chaos_068', 'mythic', 'after_playing', 'After playing this mood — Choose any player who has two or more moods (moods are cards in play). That player chooses two of their moods. Put those moods and all other moods that share a color with either of them into the discard pile.'),
(69, 'chaos_069', 'rare', 'while_in_play', 'While in play — You may play moods from the discard pile as though they were in your hand.'),
(70, 'chaos_070', 'uncommon', 'while_in_play', 'While in play — This mood''s value is 8 if there are two or more cards in the discard pile that share a color.'),
(71, 'chaos_071', 'uncommon', 'after_playing', 'After playing this mood — You may choose a player with one or more cards in their hand. If you do, that player reveals a random card from their hand and puts it on the bottom of the deck, then you draw a card.'),
(72, 'chaos_072', 'common', 'while_in_play', 'While in play — This mood''s value is 6 if there are two or more blue and/or red moods.'),
(73, 'chaos_073', 'rare', 'after_playing', 'After playing this mood — You may choose two other moods. If the two chosen moods share a color or have the same value, put them into the discard pile.'),
(74, 'chaos_074', 'mythic', 'while_in_play', 'While in play — This mood''s value increases by 1 for each card in the discard pile.'),
(75, 'chaos_075', 'common', 'after_playing', 'After playing this mood - You may put an opponent''s mood with value less than this mood''s value into the discard pile.'),
(76, 'chaos_076', 'common', 'after_playing', 'After playing this mood — Choose up to two players. For each chosen player, put one of their moods with an even value into the discard pile.'),
(77, 'chaos_077', 'common', 'while_in_play', 'While in play — This mood''s value is 7 if you have more moods than each other player. (Moods are cards in play.)'),
(78, 'chaos_078', 'common', 'after_playing', 'After playing this mood — Choose any number of players. Each chosen player discards a card from their hand.'),
(79, 'chaos_079', 'mythic', 'while_in_play', 'While in play — This mood''s value increases by 1 for each of your moods (including itself). If there are no cards in your hand, this mood''s value instead increases by 2 for each of your moods (including itself).'),
(80, 'chaos_080', 'uncommon', 'after_playing', 'After playing this mood — You may put any number of moods with total value 5 or less into the discard pile.'),
(81, 'chaos_081', 'uncommon', 'while_in_play', 'While in play — This mood''s value is 5 if any opponent has three or more cards in hand.'),
(82, 'chaos_082', 'uncommon', 'after_playing', 'After playing this mood — You may choose an opponent. If you do, they choose one of their white or blue moods and it becomes yours. After this mood is no longer in play, give the mood you took back to them (if you still have it).'),
(83, 'chaos_083', 'common', 'after_playing', 'After playing this mood - Put a red mood with value 1 named Tedium into play.'),
(84, 'chaos_084', 'common', 'after_playing', 'After playing this mood — You may put one of your other moods into the discard pile (moods are cards in play). If you do, you may play an additional mood this turn.'),
(85, 'chaos_085', 'mythic', 'after_playing', 'After playing this mood — Shuffle all moods together. Starting with you and going in turn order, deal those moods out one at a time to each player. (Moods may change players but "After playing this mood" effects won''t happen again.)'),
(86, 'chaos_086', 'common', 'after_playing', 'After playing this mood — Choose another player. That player chooses a card from their hand and gives it to you.'),
(87, 'chaos_087', 'common', 'after_playing', 'After playing this mood — You may discard a card from your hand with a 4, 5, or 6 in its top right corner. If you do, this mood''s value becomes 5.'),
(88, 'chaos_088', 'common', 'while_in_play', 'While in play — This mood''s value is 6 if there are two or more black and/or green moods.'),
(89, 'chaos_089', 'mythic', 'while_in_play', 'While in play - Whenever another one of your moods is put into the discard pile, put X red moods with value 1 named Tedium into play, where X is the value of that mood.'),
(90, 'chaos_090', 'common', 'while_in_play', 'While in play — This mood''s value is 3 if there are two or more white and/or blue moods.'),
(91, 'chaos_091', 'uncommon', 'after_playing', 'After playing this mood — Each player chooses one of their highest value moods and puts it into the discard pile.'),
(92, 'chaos_092', 'common', 'while_in_play', 'While in play — This mood''s value is 6 if you played it this round.'),
(93, 'chaos_093', 'uncommon', 'after_playing', 'After playing this mood — You may play an additional mood this turn. If you do, after scoring, put that mood into the discard pile (if it''s still in play).'),
(94, 'chaos_094', 'uncommon', 'after_playing', 'After playing this mood — You may put one of your black or green moods into the discard pile. If you do, put up to two moods each with a value of 3 or less into the discard pile.'),
(95, 'chaos_095', 'rare', 'after_playing', 'After playing this mood — You may put two of your other moods into the discard pile (moods are cards in play). If you do, this mood''s value becomes 9.'),
(96, 'chaos_096', 'rare', 'after_playing', 'After playing this mood — You may choose two moods from the same opponent. If you do, they choose one of those moods and gives it to you, then you give them one of your moods.'),
(97, 'chaos_097', 'rare', 'while_in_play', 'While in play — While scoring, you may score one of your opponents'' moods as though it were yours. (They also still score it.)'),
(98, 'chaos_098', 'uncommon', 'after_playing', 'After playing this mood — You may put all other moods with a value of 2 or less into the discard pile.'),
(99, 'chaos_099', 'uncommon', 'after_playing', 'After playing this mood — Choose 0, 1, 2, or 3. Put all other moods with the chosen value into the discard pile.'),
(100, 'chaos_100', 'rare', 'while_in_play', 'While in play - After scoring, if you have the lowest score out of all players, you may put this mood into the discard pile. If you do, choose any opponent''s mood - that mood''s owner gives it to you.'),
(101, 'chaos_101', 'common', 'after_playing', 'After playing this mood — Choose up to two players. For each chosen player, put one of their moods with a value of 3 or less into the discard pile.'),
(102, 'chaos_102', 'rare', 'while_in_play', 'While in play — At the start of each of your turns, if another player has more moods than you, you may play an additional mood this turn. (Moods are cards in play.)'),
(103, 'chaos_103', 'mythic', 'after_playing', 'After playing this mood — You may put any number of your other moods into your hand. If you do, you may play that many additional moods this turn.'),
(104, 'chaos_104', 'common', 'while_in_play', 'While in play — This mood''s value is 5 if you went first this round.'),
(105, 'chaos_105', 'rare', 'after_playing', 'After playing this mood — You may put all other moods into the discard pile.'),
(106, 'chaos_106', 'common', 'after_playing', 'After playing this mood — You may put a card from your hand on the bottom of the deck. If you do, draw a card.'),
(107, 'chaos_107', 'rare', 'after_playing', 'After playing this mood — There is no scoring this round. No one wins or loses this round. You choose which player goes first next round. (This round, no one will draw a card or get Hurt Feelings, and "after scoring" effects won''t happen.)'),
(108, 'chaos_108', 'mythic', 'after_playing', 'After playing this mood - Permanently increase the value of each of your moods that shares a color with this mood by that mood''s value.'),
(109, 'chaos_109', 'common', 'while_in_play', 'While in play — This mood''s value is 7 if there are more colors among your moods than among each other player''s moods.'),
(110, 'chaos_110', 'common', 'after_playing', 'After playing this mood — You may discard a card from your hand with a 0, 2, 4, or 6 in its top right corner. If you do, this mood''s value becomes 5.'),
(111, 'chaos_111', 'common', 'after_playing', 'After playing this mood — You may discard a card from your hand with a 1, 3, or 5 in its top right corner. If you do, this mood''s value becomes 5.'),
(112, 'chaos_112', 'common', 'while_in_play', 'While in play — This mood''s value is 6 if there are three or more moods that share a color. (Moods are cards in play.)'),
(113, 'chaos_113', 'common', 'while_in_play', 'While in play — This mood''s value is 3 if there are two or more blue and/or black moods.'),
(114, 'chaos_114', 'uncommon', 'after_playing', 'After playing this mood — You may play an additional mood this turn if it shares a color with one of your moods.'),
(115, 'chaos_115', 'common', 'while_in_play', 'While in play — This mood''s value is 6 if there are two or more red and/or white moods.'),
(116, 'chaos_116', 'uncommon', 'while_in_play', 'While in play — While scoring, you may score one of your moods an extra time.'),
(117, 'chaos_117', 'rare', 'while_in_play', 'While in play — This mood''s value increases by 1 for each mood (including itself and other players'' moods).'),
(118, 'chaos_118', 'uncommon', 'after_playing', 'After playing this mood — You may reveal a blue or black card from your hand and give it to another player. If you do, this mood''s value becomes 7.'),
(119, 'chaos_119', 'uncommon', 'while_in_play', 'While in play — This mood''s value is 7 if each player has three or more moods. (Moods are cards in play.)'),
(120, 'chaos_120', 'common', 'after_playing', 'After playing this mood - The next time an opponent plays a mood, you may permanently increase the value of one of your moods by 1.'),
(121, 'chaos_121', 'rare', 'while_in_play', 'While in play — During each of your turns (including the turn you play this mood), you may play an additional mood from the discard pile if it shares a color with one of your moods.'),
(122, 'chaos_122', 'uncommon', 'while_in_play', 'While in play — This mood''s value is 8 if a player has both a red mood and a white mood.'),
(123, 'chaos_123', 'uncommon', 'after_playing', 'After playing this mood — You may play an additional mood this turn from the discard pile.'),
(124, 'chaos_124', 'rare', 'while_in_play', 'While in play — You may play an additional mood during each of your turns (including the turn you play this mood).'),
(125, 'chaos_125', 'common', 'after_playing', 'After playing this mood — You may play an additional mood on your next turn.'),
(126, 'chaos_126', 'common', 'after_playing', 'After playing this mood - Put a green mood with value 1 named Idleness into play.'),
(127, 'chaos_127', 'mythic', 'while_in_play', 'While in play — This mood''s value is 12 if there''s a white mood, a blue mood, a black mood, a red mood, and a green mood (including this one).'),
(128, 'chaos_128', 'common', 'after_playing', 'After playing this mood — You may put a card from the discard pile into your hand. You may play an additional mood this turn.'),
(129, 'chaos_129', 'uncommon', 'while_in_play', 'While in play — This mood''s value is 6 if you have an even number of moods (including this one).'),
(130, 'chaos_130', 'rare', 'while_in_play', 'While in play — This mood''s value increases by 1 for each card in your hand.'),
(131, 'chaos_131', 'uncommon', 'while_in_play', 'While in play — This mood''s value is 6 if you have an odd number of moods (including this one).'),
(132, 'chaos_132', 'rare', 'while_in_play', 'While in play — This mood''s value is 7 if a card was put into the discard pile this round.'),
(133, 'chaos_133', 'mythic', 'after_playing', 'After playing this mood - Choose a color, then permanently increase the value of this mood by 2 for each mood of the chosen color and each card in the discard pile of the chosen color.');

-- ## Token cards
--
-- A dozen or so chaos_effects rows conjure a brand-new physical card into
-- play that was never dealt into any deck/hand -- e.g. chaos_005 "Put a
-- white mood with value 1 named Smugness into play." This is new
-- territory: every existing card is instantiated once at deal time (see
-- migration 0013's own docblock -- game_cards.id, not cards.id, is
-- already a card's true per-game identity specifically so the same
-- catalog card CAN exist more than once in a game, which is exactly what
-- a spawned token needs). Modeled as five ordinary `cards` rows (value 1,
-- one per color, vanilla -- no ability of their own, so no
-- has_to_play/has_while_in_play/has_after_playing flag needs setting and
-- no DefaultEffectRegistry entry is needed, the same "five commons and
-- Creativity" precedent 0003's own docblock already establishes for a
-- printed vanilla card) rather than a parallel non-card token model, so
-- every existing card-shaped code path (catalogRow(), valueOf(), color
-- filters, scoring) already understands them for free.
--
-- is_token marks all five so real deck-building (Structure/Power/
-- Quick Draft pool building/etc., see CardCatalog.php and
-- GameService.php's own rarity-scoped deck queries) can exclude them --
-- a token is never legitimately drawable or draftable, only ever created
-- directly into play by a chaos effect. rarity is set to 'common'
-- arbitrarily (never read for a token -- rarity-scoped deck queries
-- already filter is_token = 0 first) rather than left NULL, since
-- `cards.rarity` is NOT NULL for every other row and giving these one
-- real, uninterpreted value is simpler than loosening that column's own
-- constraint for five rows that will never actually consult it.
ALTER TABLE cards
    ADD COLUMN is_token TINYINT(1) NOT NULL DEFAULT 0 AFTER rarity;

INSERT INTO cards (id, name, effect_key, color, rarity, is_token, base_value, alt_value, has_to_play_ability, has_while_in_play_ability, has_after_playing_ability, rules_text) VALUES
(134, 'Smugness', 'smugness_token', 'white', 'common', 1, 1, NULL, 0, 0, 0, 'A token mood conjured into play by certain Chaos Draft effects (issue #405). No printed ability of its own.'),
(135, 'Unconcern', 'unconcern_token', 'blue', 'common', 1, 1, NULL, 0, 0, 0, 'A token mood conjured into play by certain Chaos Draft effects (issue #405). No printed ability of its own.'),
(136, 'Passivity', 'passivity_token', 'black', 'common', 1, 1, NULL, 0, 0, 0, 'A token mood conjured into play by certain Chaos Draft effects (issue #405). No printed ability of its own.'),
(137, 'Tedium', 'tedium_token', 'red', 'common', 1, 1, NULL, 0, 0, 0, 'A token mood conjured into play by certain Chaos Draft effects (issue #405). No printed ability of its own.'),
(138, 'Idleness', 'idleness_token', 'green', 'common', 1, 1, NULL, 0, 0, 0, 'A token mood conjured into play by certain Chaos Draft effects (issue #405). No printed ability of its own.');

-- ## deck_type: a twelfth entry, 'chaos_draft'
--
-- Reuses Quick Draft's own pool-building/pick/deck-building/best-of-three
-- plumbing entirely unchanged (see php-app/README.md's "Quick Draft"
-- section) -- Chaos Draft is a variation on Quick Draft specifically, per
-- the issue, layering the per-round effect-attachment mechanic below on
-- top of identical drafting. GameService::createGame() doesn't accept
-- this value yet (a later migration/PR finishes that wiring); adding it
-- to the enum now is schema-only groundwork, same as every prior
-- deck_type migration.
ALTER TABLE games
    MODIFY COLUMN deck_type ENUM('structure', 'power', 'jceddys_75', 'custom', 'custom_duel', 'quick_draft', 'one_of_each', 'winston_draft', 'grid_draft', 'rotisserie_draft', 'tiered_rotisserie_draft', 'chaos_draft') NOT NULL DEFAULT 'structure';

-- ## The round's own two rarities
--
-- "Each round's two rarities are chosen independently at random... every
-- player's own pair is drawn from the same two rarities that round" --
-- the round itself fixes which two tiers are in play (rolled once,
-- shared by every player/team that round), while each player's own two
-- SPECIFIC effects are independently drawn from within those tiers (see
-- chaos_draft_offers below). Nullable, and only ever set for a
-- chaos_draft game's own rounds -- every other deck_type simply never
-- populates these, the same "NULL means not applicable to this game"
-- convention skip_scoring_source_card_id etc. already use on this table.
ALTER TABLE game_rounds
    ADD COLUMN chaos_rarity_1 ENUM('common', 'uncommon', 'rare', 'mythic') DEFAULT NULL AFTER skip_scoring_owner_game_player_id,
    ADD COLUMN chaos_rarity_2 ENUM('common', 'uncommon', 'rare', 'mythic') DEFAULT NULL AFTER chaos_rarity_1;

-- ## The attached effect itself
--
-- "Once attached, an effect stays on that card for the rest of the game
-- ... and stacks with ... the card's own printed ability. A card can
-- carry at most one Chaos Draft effect at a time -- attaching a new one
-- ... overwrites (replaces) the existing one." A plain nullable column on
-- game_cards itself (rather than a separate side table keyed by card)
-- models this precisely: it travels with the physical card through every
-- zone it moves to for the rest of the game exactly like `suppressions`/
-- `effect_state` already do, "at most one at a time" falls out of it
-- being a single column rather than a list, and "overwrites" is simply
-- assigning a new value over the old one -- no separate attach-history
-- bookkeeping needed. BoardStateRepository's own load()/save() gain this
-- column alongside those two.
ALTER TABLE game_cards
    ADD COLUMN chaos_effect_id SMALLINT UNSIGNED DEFAULT NULL AFTER effect_state,
    ADD CONSTRAINT fk_game_cards_chaos_effect FOREIGN KEY (chaos_effect_id) REFERENCES chaos_effects (id);

-- ## The per-round choice, and (Open Team Play) its propose/confirm
--
-- One row per eligible player OR team per round (never both -- exactly
-- one of game_player_id/team_id is set, matching which the game's own
-- format uses): a plain player-scoped row for non-team and Closed Team
-- Play (independently resolved by that one player, no negotiation), or a
-- team-scoped row for Open Team Play, confirmed by the maintainer to need
-- the same propose/confirm two-step game_team_decisions already uses for
-- the turn-order/draw-recipient team decisions (issue #360) -- one
-- teammate proposes an effect + a card to attach it to (in EITHER
-- teammate's hand, per the issue), the other must confirm before it's
-- final, rather than either teammate being able to force a permanent
-- card modification unilaterally. For a non-team/closed_team row, phase
-- is simply never advanced past its own default -- there's no second
-- player to confirm with, so the resolving player's own single choice
-- (chosen_effect_id/attach_game_card_id) is written directly alongside
-- resolved_at in one step.
--
-- effect_id_1/effect_id_2 are the two specific chaos_effects offered to
-- THIS player/team (independently drawn from the round's own
-- chaos_rarity_1/chaos_rarity_2, so different players/teams the same
-- round usually see different specific effects even though every pair is
-- drawn from the same two rarity tiers). "If a player has no cards in
-- hand at the start of a round, they aren't offered a choice at all" --
-- no row is created for them that round, rather than an offer no legal
-- attach target could ever resolve.
CREATE TABLE IF NOT EXISTS chaos_draft_offers (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    game_id INT UNSIGNED NOT NULL,
    game_round_id INT UNSIGNED NOT NULL,
    game_player_id INT UNSIGNED DEFAULT NULL,
    team_id TINYINT UNSIGNED DEFAULT NULL,
    effect_id_1 SMALLINT UNSIGNED NOT NULL,
    effect_id_2 SMALLINT UNSIGNED NOT NULL,
    phase ENUM('propose', 'confirm') NOT NULL DEFAULT 'propose',
    proposer_game_player_id INT UNSIGNED DEFAULT NULL,
    chosen_effect_id SMALLINT UNSIGNED DEFAULT NULL,
    attach_game_card_id INT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resolved_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_chaos_offers_player_round (game_round_id, game_player_id),
    UNIQUE KEY uq_chaos_offers_team_round (game_round_id, team_id),
    CONSTRAINT fk_chaos_offers_game FOREIGN KEY (game_id) REFERENCES games (id) ON DELETE CASCADE,
    CONSTRAINT fk_chaos_offers_round FOREIGN KEY (game_round_id) REFERENCES game_rounds (id) ON DELETE CASCADE,
    CONSTRAINT fk_chaos_offers_player FOREIGN KEY (game_player_id) REFERENCES game_players (id) ON DELETE CASCADE,
    CONSTRAINT fk_chaos_offers_proposer FOREIGN KEY (proposer_game_player_id) REFERENCES game_players (id) ON DELETE RESTRICT,
    CONSTRAINT fk_chaos_offers_effect_1 FOREIGN KEY (effect_id_1) REFERENCES chaos_effects (id),
    CONSTRAINT fk_chaos_offers_effect_2 FOREIGN KEY (effect_id_2) REFERENCES chaos_effects (id),
    CONSTRAINT fk_chaos_offers_chosen_effect FOREIGN KEY (chosen_effect_id) REFERENCES chaos_effects (id),
    CONSTRAINT fk_chaos_offers_attach_card FOREIGN KEY (attach_game_card_id) REFERENCES game_cards (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE schema_version SET version = '1.28.39' WHERE id = 1;
