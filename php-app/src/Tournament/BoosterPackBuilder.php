<?php

declare(strict_types=1);

namespace MoodSwings\Tournament;

/**
 * Pure booster generation for the Booster Draft tournament format -- no
 * database access. Given the whole catalog's card ids grouped by
 * rarity, buildBooster() returns exactly 15 distinct catalog card ids
 * (no card repeats within a single booster -- the same "distinct ids"
 * convention GameService's own algorithmic decks, e.g.
 * buildStructureDeckCardIds(), already follow): a guaranteed 1 Mythic,
 * 2 Rares, 4 Uncommons, and 8 Commons, drawn uniformly at random within
 * each rarity. Two boosters (one drafted from the left, one from the
 * right -- see TournamentPodRepository's own docblock) give each player
 * 30 cards total.
 */
final class BoosterPackBuilder
{
    private const RARITY_COUNTS = ['mythic' => 1, 'rare' => 2, 'uncommon' => 4, 'common' => 8];

    /**
     * @param array<string, int[]> $cardIdsByRarity rarity ('mythic'/'rare'/'uncommon'/'common') => that rarity's own catalog card ids
     * @return int[] exactly 15 distinct catalog card ids
     */
    public function buildBooster(array $cardIdsByRarity): array
    {
        $cardIds = [];
        foreach (self::RARITY_COUNTS as $rarity => $count) {
            $pool = $cardIdsByRarity[$rarity] ?? [];
            if (count($pool) < $count) {
                throw new TournamentStateException("Not enough {$rarity} cards in the catalog to build a booster");
            }

            $chosenKeys = (array) array_rand($pool, $count);
            foreach ($chosenKeys as $key) {
                $cardIds[] = $pool[$key];
            }
        }

        return $cardIds;
    }
}
