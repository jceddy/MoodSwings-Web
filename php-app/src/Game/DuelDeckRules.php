<?php

declare(strict_types=1);

namespace MoodSwings\Game;

use MoodSwings\Game\Exceptions\GameStateException;

/**
 * The deck-building rules a 'custom_duel' game's creator defines, and
 * that each of the two players' own submitted decklist (see
 * DecklistParser, GameService::submitCustomDuelDeck()) must satisfy: a
 * minimum total card count, an optional maximum total count per rarity,
 * and an optional maximum copies-of-a-single-card count per rarity.
 * Omitting a rarity from $rarityLimits or $duplicateLimits means "no
 * restriction for that rarity" -- both maps may be partial or empty.
 *
 * The three built-in presets (forPreset()) approximate the existing
 * algorithmically-assembled deck types (see GameService's own
 * buildStructureDeckCardIds()/buildPowerDeckCardIds()/
 * buildJceddys75DeckCardIds()) as closely as this rule shape allows.
 * 'structure' and 'jceddys_75' land on an exact match: since every
 * rarity is capped and $minCards equals the sum of those caps, a deck
 * meeting the minimum while respecting every cap is *forced* to hit each
 * cap exactly (if any rarity fell short, the total couldn't reach
 * $minCards without another rarity exceeding its own cap) -- so those two
 * presets reproduce the generators' own exact rarity splits. 'power' is
 * only an approximation: the real generator guarantees exactly one
 * Mythic among 15 singleton cards pooled from every rarity, but this rule
 * shape has no way to *require* a rarity be present (only cap it), so
 * the closest available rule is "at least 15 cards, at most 1 Mythic,
 * singleton" -- a Mythic-less 15-card deck is legal under the preset even
 * though the real Power generator could never produce one.
 */
final class DuelDeckRules
{
    private const MINIMUM_MIN_CARDS = 7;

    /** Mirrors GameService::STRUCTURE_DECK_RARITY_COUNTS. */
    private const STRUCTURE_RARITY_LIMITS = ['common' => 23, 'uncommon' => 14, 'rare' => 6, 'mythic' => 2];

    /** Mirrors GameService::POWER_DECK_NON_MYTHIC_COUNT (14) plus its one guaranteed Mythic. */
    private const POWER_MIN_CARDS = 15;
    private const POWER_RARITY_LIMITS = ['mythic' => 1];

    /**
     * Mirrors GameService::JCEDDYS_75_DECK_RARITY_SPEC's per-color counts,
     * summed across all 5 colors (this rule shape has no notion of color,
     * only rarity) -- 1/2/4/8 per color * 5 colors = 5/10/20/40.
     */
    private const JCEDDYS_75_RARITY_LIMITS = ['mythic' => 5, 'rare' => 10, 'uncommon' => 20, 'common' => 40];
    private const JCEDDYS_75_DUPLICATE_LIMITS = ['mythic' => 1, 'rare' => 1, 'uncommon' => 2, 'common' => 3];

    private const SINGLETON_DUPLICATE_LIMITS = ['common' => 1, 'uncommon' => 1, 'rare' => 1, 'mythic' => 1];

    /**
     * @param array<string, int> $rarityLimits rarity => max total cards of that rarity allowed (missing key = unrestricted)
     * @param array<string, int> $duplicateLimits rarity => max copies of any single card of that rarity allowed (missing key = unrestricted)
     */
    public function __construct(
        public readonly int $minCards,
        public readonly array $rarityLimits = [],
        public readonly array $duplicateLimits = [],
    ) {
        if ($minCards < self::MINIMUM_MIN_CARDS) {
            throw new GameStateException('The minimum card count for a duel deck cannot be lower than ' . self::MINIMUM_MIN_CARDS . '.');
        }
    }

    public static function forPreset(string $preset): self
    {
        return match ($preset) {
            'structure' => new self(array_sum(self::STRUCTURE_RARITY_LIMITS), self::STRUCTURE_RARITY_LIMITS, self::SINGLETON_DUPLICATE_LIMITS),
            'power' => new self(self::POWER_MIN_CARDS, self::POWER_RARITY_LIMITS, self::SINGLETON_DUPLICATE_LIMITS),
            'jceddys_75' => new self(array_sum(self::JCEDDYS_75_RARITY_LIMITS), self::JCEDDYS_75_RARITY_LIMITS, self::JCEDDYS_75_DUPLICATE_LIMITS),
            default => throw new GameStateException("Unknown duel deck rules preset \"{$preset}\"."),
        };
    }

    /**
     * @param int[] $cardIds resolved catalog card ids, one entry per copy
     * @param array<int, array{name: string, rarity: string}> $catalogById
     */
    public function validate(array $cardIds, array $catalogById, string $deckLabel = 'Your deck'): void
    {
        if (count($cardIds) < $this->minCards) {
            throw new GameStateException("{$deckLabel} has only " . count($cardIds) . ' card(s), but at least ' . $this->minCards . ' are required.');
        }

        $rarityCounts = [];
        foreach ($cardIds as $cardId) {
            $rarity = $catalogById[$cardId]['rarity'];
            $rarityCounts[$rarity] = ($rarityCounts[$rarity] ?? 0) + 1;
        }

        foreach ($this->rarityLimits as $rarity => $limit) {
            $count = $rarityCounts[$rarity] ?? 0;
            if ($count > $limit) {
                throw new GameStateException("{$deckLabel} has {$count} {$rarity} card(s), but at most {$limit} {$rarity} card(s) are allowed.");
            }
        }

        foreach (array_count_values($cardIds) as $cardId => $copies) {
            $rarity = $catalogById[$cardId]['rarity'];
            $limit = $this->duplicateLimits[$rarity] ?? null;
            if ($limit !== null && $copies > $limit) {
                $name = $catalogById[$cardId]['name'];
                $copyNoun = $limit === 1 ? 'copy' : 'copies';
                throw new GameStateException("{$deckLabel} has {$copies} copies of \"{$name}\" ({$rarity}), but at most {$limit} {$copyNoun} of any {$rarity} card are allowed.");
            }
        }
    }

    /** @return array{min_cards: int, rarity_limits: array<string,int>, duplicate_limits: array<string,int>} */
    public function toArray(): array
    {
        return [
            'min_cards' => $this->minCards,
            'rarity_limits' => $this->rarityLimits,
            'duplicate_limits' => $this->duplicateLimits,
        ];
    }
}
