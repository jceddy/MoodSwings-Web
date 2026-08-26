-- chaos_065 (Grief's chaos analog, backed by ChaosGrantExtraPlayEffect(2,
-- ['source' => 'discard'])) was seeded with "You may play up to two
-- additional CARDS this turn from the discard pile" -- a transcription
-- slip from migration 0183. The printed Grief text (and every other
-- discard-sourced extra-play card/chaos effect: Angst/chaos_054,
-- Harmony/chaos_123, Grace/chaos_121) consistently says "moods", not
-- "cards" -- what's played from the discard pile is a mood, the same
-- terminology this game uses everywhere else. Reported live. The effect
-- implementation itself is unaffected (this is display text only).
UPDATE chaos_effects SET rules_text = 'After playing this mood — You may play up to two additional moods this turn from the discard pile.' WHERE effect_key = 'chaos_065';

UPDATE schema_version SET version = '1.28.48' WHERE id = 1;
