-- Bot fixes: BotPlayerService now avoids leading with Fear unless the
-- bot's hand holds a mood-counting or blue-caring synergy card (Fear's
-- own printed value is always 0, so on its own it's a worthless opening/
-- filler play), and avoids leading with Denial unless it has a real
-- target to bounce (a round-winning pair, a significant swing against a
-- non-teammate opponent, or a replay opportunity) or its own plain point
-- value alone would win the round. Two small bot-policy fixes, so this
-- only bumps the patch version per CLAUDE.md's own versioning convention.
UPDATE schema_version SET version = '1.32.3' WHERE id = 1;
