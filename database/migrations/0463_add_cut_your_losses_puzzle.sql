-- 26th puzzle, "Cut Your Losses" (a Hate puzzle: "Each player has two round
-- wins, and your opponent played first this round. Your opponent has Discipline,
-- Frustration, Complacency and Boredom in play. You have Joy and Pity in play
-- and hold Hate and Apathy, with Disregard on top of your deck. You have two
-- plays. Win the game this round."). No new puzzle infrastructure: goal_type
-- 'win_game', with Joy seeded in play and as the source of the second play
-- (extra_play_source_card_id).
--
-- Setup: both sides have two round wins (games.wins_needed is 3), so winning
-- this round wins the game; the opponent went first, so a tie goes to them.
-- Hate (black 0): after playing it, you may put any mood on the bottom of the
-- deck; if you do, you draw a card. Whoever's mood it is, YOU draw.
--   Opponent: Discipline (white 6, but 3 while two or more black and/or red
--     moods are in play), Frustration (red 6, but 3 while two or more white
--     and/or blue moods are in play), Complacency (white 4), Boredom (red 4).
--     Discipline is held down by Frustration + Boredom (red) and Pity (black);
--     Frustration is held down by Discipline + Complacency (white).
--     3 + 3 + 4 + 4 = 14.
--   Solver: Joy (green 3) and Pity (black 3, but 6 while two or more blue
--     and/or red moods are in play -- the opponent's two reds): 3 + 6 = 9.
--     Hand: Hate and Apathy (black 4). On top of the deck: Disregard (green 6,
--     but 3 while two or more blue and/or black moods are in play).
--
-- The one winning line (exhaustive search through the real GameService flow,
-- every legal play order and every Hate target): HATE putting ITSELF on the
-- bottom of the deck, then DISREGARD. Without Hate only Pity is black, so
-- Disregard stays at 6: Joy 3 + Pity 6 + Disregard 6 = 15 against 14. A win by one.
-- The traps: Hate is worth 0 and every other target looks like it should help,
-- but each one backfires. Boredom (the opponent's 4) is one of the reds feeding
-- your Pity (Pity drops to 3); Complacency (4) is one of the whites holding
-- Frustration down (it jumps to 6); Discipline and Frustration keep each other
-- alive; your own moods are worth more to you than to anyone. Leaving Hate in
-- play makes Disregard a 3 (Pity and Hate are both black), and Apathy is no
-- better (13 against 14). Several lines tie 10-10 / 13-13, and a tie goes to
-- the opponent.
INSERT INTO puzzles (
    slug, title, description, hint, difficulty,
    starting_hand_card_ids, starting_in_play_card_ids, deck_card_ids, starting_discard_card_ids,
    opponent_hand_card_ids, opponent_in_play_card_ids,
    solver_round_wins, opponent_round_wins, solver_goes_first, prelude_log,
    goal_type, goal_params, max_plays, extra_play_source_card_id, active
) VALUES (
    'cut-your-losses',
    'Cut Your Losses',
    'Each player has two round wins, and your opponent played first this round. Your opponent has Discipline, Frustration, Complacency and Boredom in play. You have Joy and Pity in play and hold Hate and Apathy, with Disregard on top of your deck. You have two plays. Win the game this round.',
    'Hate lets you bottom any mood and draw -- but before you pick one, work out what each mood in play is holding up, and what Hate itself is holding down.',
    'hard',
    '[66, 55]', '[125, 72]', '[113]', '[]',
    '[]', '[9, 90, 5, 83]',
    2, 2, 0, NULL,
    'win_game', '{}', NULL, 125, 1
);

UPDATE schema_version SET version = '1.62.12' WHERE id = 1;
