-- Rationalization's printed text is "After playing this mood, you may
-- choose one: ..." -- the 0003 catalog seed dropped "you may", which made
-- the effect look mandatory and RationalizationEffect was implemented to
-- match that (wrongly) mandatory reading, forcing a mode choice on every
-- play instead of letting the player decline. This corrects the stored
-- text to match the printed card; RationalizationEffect itself is fixed
-- in the same change to actually treat 'mode' as optional.
UPDATE cards SET rules_text = 'After playing this mood, you may choose one: put your hand on the bottom of the deck then draw that many cards, or choose left or right and have each player simultaneously give their hand to the next player in that direction.' WHERE id = 49;

UPDATE schema_version SET version = '0.6.2' WHERE id = 1;
