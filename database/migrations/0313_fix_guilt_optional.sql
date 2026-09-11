-- Reported live: "Guilt should actually have the same pattern as
-- Hesitation and Contempt."
--
-- Guilt's printed text is "After playing this mood, you may choose one:
-- ..." -- the 0003 catalog seed dropped "you may", the same
-- transcription mistake migrations 0030/0055 already fixed for
-- Rationalization/Hesitation, which made the effect look mandatory and
-- GuiltEffect was implemented to match that (wrongly) mandatory reading,
-- forcing a mode choice on every play instead of letting the player
-- decline. This corrects the stored text to match the printed card;
-- GuiltEffect itself, and CardChoiceSchema's own 'guilt' entry (its
-- 'mode' field is now 'required' => false, with the new 'requires_mode'
-- flag added to target_mood_id -- see CardChoiceSchemaTest and
-- game.js's own cardHasATargetWithoutItsRequiredMode()), are fixed in
-- the same change to actually treat 'mode' as optional.
UPDATE cards SET rules_text = 'After playing this mood, you may choose one: suppress a black or red mood for as long as you have this mood, or suppress all black and red moods for as long as you have this mood.' WHERE id = 14;

UPDATE schema_version SET version = '1.40.6' WHERE id = 1;
