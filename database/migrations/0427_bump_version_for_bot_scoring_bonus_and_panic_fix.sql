-- Bots now take Enthusiasm/Passion scoring bonuses (they used to decline
-- every one), search rollouts count those bonuses, and the tactical bot
-- considers Panic bouncing an opponent's mood. Pure code change; no
-- schema change.
UPDATE schema_version SET version = '1.59.3' WHERE id = 1;
