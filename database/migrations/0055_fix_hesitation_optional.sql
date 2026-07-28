-- Hesitation's printed text is "After playing this mood, you may choose
-- one: ..." -- the 0003 catalog seed dropped "you may", the same
-- transcription mistake 0030 already fixed for Rationalization, which
-- made the effect look mandatory and HesitationEffect was implemented to
-- match that (wrongly) mandatory reading, forcing a mode choice on every
-- play instead of letting the player decline. This corrects the stored
-- text to match the printed card; HesitationEffect itself is fixed in
-- the same change to actually treat 'mode' as optional.
UPDATE cards SET rules_text = 'After playing this mood, you may choose one: put a red or green mood into its player''s hand, or put all red and green moods into their players'' hands.' WHERE id = 41;

UPDATE schema_version SET version = '1.5.1' WHERE id = 1;
