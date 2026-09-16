<?php

declare(strict_types=1);

namespace MoodSwings\Tournament;

/**
 * Pure booster generation for the Booster Draft tournament format -- no
 * database access. Given the whole catalog's card ids grouped by rarity,
 * buildBooster() returns exactly 15 distinct catalog card ids (no card
 * repeats within a single booster -- the same "distinct ids" convention
 * GameService's own algorithmic decks, e.g. buildStructureDeckCardIds(),
 * already follow) drawn slot-by-slot at fixed odds:
 *
 *   slot 1: 2/3 mythic, 1/3 rare
 *   slot 2: always rare
 *   slot 3: 2/3 rare, 1/3 uncommon
 *   slots 4-7: always uncommon (4 cards)
 *   slot 8: 1/3 uncommon, 2/3 common
 *   slots 9-15: always common (7 cards)
 *
 * On average that's 2/3 mythic + 2 rares + 14/3 uncommons + 23/3 commons
 * per booster (summing to 15), approximating a real booster's weighted
 * rarity slots rather than guaranteeing a fixed count of each -- two
 * boosters (one drafted from the left, one from the right -- see
 * TournamentPodRepository's own docblock) give each player 30 cards
 * total.
 */
final class BoosterPackBuilder
{
    /**
     * @param array<string, int[]> $cardIdsByRarity rarity ('mythic'/'rare'/'uncommon'/'common') => that rarity's own catalog card ids
     * @return int[] exactly 15 distinct catalog card ids
     */
    public function buildBooster(array $cardIdsByRarity): array
    {
        // A working copy this booster's own picks are removed from as we
        // go, so the same card can never appear twice in one booster --
        // deliberately NOT shared across boosters (a different booster,
        // even one opened by the same pod for the same player, draws
        // from a fresh full copy) -- see the class docblock.
        $available = $cardIdsByRarity;

        $cardIds = [];
        $cardIds[] = $this->drawFrom($available, mt_rand(1, 3) <= 2 ? 'mythic' : 'rare');
        $cardIds[] = $this->drawFrom($available, 'rare');
        $cardIds[] = $this->drawFrom($available, mt_rand(1, 3) <= 2 ? 'rare' : 'uncommon');
        for ($i = 0; $i < 4; $i++) {
            $cardIds[] = $this->drawFrom($available, 'uncommon');
        }
        $cardIds[] = $this->drawFrom($available, mt_rand(1, 3) === 1 ? 'uncommon' : 'common');
        for ($i = 0; $i < 7; $i++) {
            $cardIds[] = $this->drawFrom($available, 'common');
        }

        return $cardIds;
    }

    /** @param array<string, int[]> $available */
    private function drawFrom(array &$available, string $rarity): int
    {
        $pool = $available[$rarity] ?? [];
        if ($pool === []) {
            throw new TournamentStateException("Not enough {$rarity} cards in the catalog to build a booster");
        }

        $key = array_rand($pool);
        $cardId = $pool[$key];
        unset($available[$rarity][$key]);

        return $cardId;
    }
}
