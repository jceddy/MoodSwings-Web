-- Bot fix: BotPlayerService now avoids leading with Grief while the
-- discard pile is completely empty (its own two extra plays are both
-- restricted to cards FROM the discard pile, so with nothing there the
-- grant accomplishes nothing) -- the same sortPriorityValue() treatment
-- Harmony already got. A small bot-policy fix, so this only bumps the
-- patch version per CLAUDE.md's own versioning convention.
UPDATE schema_version SET version = '1.32.1' WHERE id = 1;
