-- No schema change: practice bots (issue #140) previously always played
-- Fury (id 91) whenever it was the highest-value playable card, even
-- though Fury costs the acting player their own highest-value mood too
-- ("each player chooses one of their highest value moods and puts it
-- into the discard pile" targets every player, including whoever plays
-- it -- see FuryEffect's own docblock). BotPlayerService::isWorthPlaying()
-- now vetoes Fury unless at least one opponent's own highest-value mood
-- is worth more than the bot's own, so it no longer trades its own best
-- mood for something equal or worse.
UPDATE schema_version SET version = '1.14.1' WHERE id = 1;
