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
