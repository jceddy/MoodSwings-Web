-- An optional fourth 'custom_duel' deck-building rule (see DuelDeckRules):
-- a per-rarity flag requiring that rarity's cards be split evenly across
-- all 5 colors (e.g. 5 mythic total => exactly 1 of each color). Stored
-- as the JSON array of rarity names the flag is set for (a missing
-- rarity means no such requirement), same shape as
-- custom_duel_rarity_limits/custom_duel_duplicate_limits are `{rarity:
-- count}` maps for their own two rules. Locked to all four rarities for
-- the 'jceddys_75' rules preset, matching that generator's own "N per
-- color, for every color" guarantee.
ALTER TABLE games
    ADD COLUMN custom_duel_even_color_distribution_rarities JSON DEFAULT NULL AFTER custom_duel_duplicate_limits;
