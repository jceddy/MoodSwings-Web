<?php

declare(strict_types=1);

namespace MoodSwings\Rules;

use InvalidArgumentException;

/**
 * The full state of one game needed to resolve card effects and compute
 * scores: whose hand/the deck/the discard pile has which cards, and which
 * cards are currently in play (and how). This is a pure in-memory model --
 * loading it from and persisting it back to the game_cards/game_players
 * tables is the job of a future GameService, deliberately kept separate so
 * the rules themselves can be unit tested without a database.
 *
 * Card values are never cached: valueOf() always computes fresh from the
 * current state, which is what makes the Extended Rules' resolution order
 * (apply "while in play" effects, then "after playing" effects, repeating
 * until stable) work for free -- there's no stale value to invalidate.
 */
final class BoardState
{
    /** @var array<int, int[]> playerId => hand card ids, in no particular order */
    private array $hands;

    /** @var int[] deck card ids; index 0 is the top of the deck */
    private array $deck;

    /** @var int[] discard pile card ids; an unordered set -- no card in the game cares about discard-pile order */
    private array $discard;

    /** @var array<int, MoodInPlay> cardId => the mood currently in play */
    private array $moodsInPlay = [];

    private ?int $currentPlayerId = null;

    /**
     * @var array<int, ?array{type: string, values?: int[]}> one entry per
     * outstanding "play an additional mood" grant this turn. null means
     * unconditional (e.g. Charity); a restriction array means the grant
     * only covers a card matching it (e.g. Benevolence's "if it doesn't
     * share a color with any of your moods") -- see grantAllows().
     */
    private array $playGrants = [];

    /**
     * @param array<int, array{color:string,rarity:string,baseValue:int,altValue:?int,effectKey:string,hasToPlay:bool,hasWhileInPlay:bool,hasAfterPlaying:bool,rulesText:string}> $catalog card id => catalog row
     * @param int[] $playerOrder seat order (turn order) for this game
     * @param array<int, int[]> $hands playerId => hand card ids
     * @param int[] $deck card ids, index 0 = top
     * @param int[] $discard card ids
     */
    public function __construct(
        private readonly array $catalog,
        private readonly EffectRegistry $registry,
        private readonly array $playerOrder,
        array $hands = [],
        array $deck = [],
        array $discard = [],
    ) {
        $this->hands = $hands;
        $this->deck = $deck;
        $this->discard = $discard;
    }

    /** @return array{color:string,rarity:string,baseValue:int,altValue:?int,effectKey:string,hasToPlay:bool,hasWhileInPlay:bool,hasAfterPlaying:bool,rulesText:string} */
    public function catalogRow(int $cardId): array
    {
        return $this->catalog[$cardId] ?? throw new InvalidArgumentException("Unknown card id {$cardId}");
    }

    /** @return int[] */
    public function playerOrder(): array
    {
        return $this->playerOrder;
    }

    // --- zones ---

    /** @return int[] */
    public function hand(int $playerId): array
    {
        return $this->hands[$playerId] ?? [];
    }

    public function isInHand(int $playerId, int $cardId): bool
    {
        return in_array($cardId, $this->hands[$playerId] ?? [], true);
    }

    public function isInPlay(int $cardId): bool
    {
        return isset($this->moodsInPlay[$cardId]);
    }

    /** @return array<int, MoodInPlay> */
    public function moodsInPlay(): array
    {
        return $this->moodsInPlay;
    }

    /** @return array<int, MoodInPlay> */
    public function moodsOwnedBy(int $playerId): array
    {
        return array_filter($this->moodsInPlay, static fn (MoodInPlay $mood) => $mood->ownerId === $playerId);
    }

    public function ownerOf(int $cardId): int
    {
        return $this->moodInPlay($cardId)->ownerId;
    }

    /** @return int[] */
    public function discardPile(): array
    {
        return $this->discard;
    }

    /** @return int[] */
    public function deck(): array
    {
        return $this->deck;
    }

    private function moodInPlay(int $cardId): MoodInPlay
    {
        return $this->moodsInPlay[$cardId] ?? throw new InvalidArgumentException("Card {$cardId} is not in play");
    }

    // --- movement between zones ---

    public function moveHandToInPlay(int $playerId, int $cardId, ?int $copiedCardId = null): void
    {
        $this->removeFromHand($playerId, $cardId);
        $this->moodsInPlay[$cardId] = new MoodInPlay($cardId, $playerId, $copiedCardId);
    }

    public function moveInPlayToDiscard(int $cardId): void
    {
        $this->moodInPlay($cardId);
        unset($this->moodsInPlay[$cardId]);
        $this->discard[] = $cardId;
    }

    public function moveInPlayToHand(int $cardId): void
    {
        $owner = $this->moodInPlay($cardId)->ownerId;
        unset($this->moodsInPlay[$cardId]);
        $this->hands[$owner][] = $cardId;
    }

    public function moveInPlayToBottomOfDeck(int $cardId): void
    {
        $this->moodInPlay($cardId);
        unset($this->moodsInPlay[$cardId]);
        $this->deck[] = $cardId;
    }

    public function moveHandToDiscard(int $playerId, int $cardId): void
    {
        $this->removeFromHand($playerId, $cardId);
        $this->discard[] = $cardId;
    }

    public function moveHandToBottomOfDeck(int $playerId, int $cardId): void
    {
        $this->removeFromHand($playerId, $cardId);
        $this->deck[] = $cardId;
    }

    public function moveDiscardToHand(int $playerId, int $cardId): void
    {
        $this->removeFromDiscard($cardId);
        $this->hands[$playerId][] = $cardId;
    }

    public function giveInPlayToPlayer(int $cardId, int $newOwnerId): void
    {
        $this->moodInPlay($cardId)->ownerId = $newOwnerId;
    }

    /** Fascination: hands a card directly from one player's hand to another's (e.g. "give it to another player"), rather than routing it through discard/deck. */
    public function giveHandCardToPlayer(int $fromPlayerId, int $toPlayerId, int $cardId): void
    {
        $this->removeFromHand($fromPlayerId, $cardId);
        $this->hands[$toPlayerId][] = $cardId;
    }

    public function drawCard(int $playerId): ?int
    {
        $cardId = array_shift($this->deck);
        if ($cardId === null) {
            return null;
        }
        $this->hands[$playerId][] = $cardId;

        return $cardId;
    }

    private function removeFromHand(int $playerId, int $cardId): void
    {
        $hand = $this->hands[$playerId] ?? [];
        $index = array_search($cardId, $hand, true);
        if ($index === false) {
            throw new InvalidArgumentException("Card {$cardId} is not in player {$playerId}'s hand");
        }
        unset($hand[$index]);
        $this->hands[$playerId] = array_values($hand);
    }

    private function removeFromDiscard(int $cardId): void
    {
        $index = array_search($cardId, $this->discard, true);
        if ($index === false) {
            throw new InvalidArgumentException("Card {$cardId} is not in the discard pile");
        }
        unset($this->discard[$index]);
        $this->discard = array_values($this->discard);
    }

    // --- persistence hydration ---
    //
    // These two methods exist for a GameService/repository to reconstruct
    // a BoardState exactly as it was persisted, bypassing the normal
    // hand-to-play or fresh-turn transitions (which don't apply when the
    // mood is already in play, or the turn is already partway through, by
    // the time a request loads the state back from the database).

    /** @param array<string, mixed> $effectState */
    public function restoreMoodInPlay(
        int $cardId,
        int $ownerId,
        ?int $copiedCardId,
        bool $isSuppressed,
        ?string $suppressionExpiry,
        ?int $suppressionSourceCardId,
        array $effectState,
    ): void {
        $this->moodsInPlay[$cardId] = new MoodInPlay(
            $cardId,
            $ownerId,
            $copiedCardId,
            $isSuppressed,
            $suppressionExpiry,
            $suppressionSourceCardId,
            $effectState,
        );
    }

    /** @param array<int, ?array{type: string, values?: int[]}> $playGrants */
    public function restoreTurnState(?int $currentPlayerId, array $playGrants): void
    {
        $this->currentPlayerId = $currentPlayerId;
        $this->playGrants = $playGrants;
    }

    // --- suppression ---

    public function suppress(int $cardId, string $expiry, ?int $sourceCardId = null): void
    {
        $mood = $this->moodInPlay($cardId);
        $mood->isSuppressed = true;
        $mood->suppressionExpiry = $expiry;
        $mood->suppressionSourceCardId = $sourceCardId;
    }

    public function isSuppressed(int $cardId): bool
    {
        return $this->moodInPlay($cardId)->isSuppressed;
    }

    /** Clears every suppression whose source is $sourceCardId (e.g. that mood left play). */
    public function clearSuppressionsFrom(int $sourceCardId): void
    {
        foreach ($this->moodsInPlay as $mood) {
            if ($mood->suppressionSourceCardId === $sourceCardId) {
                $mood->isSuppressed = false;
                $mood->suppressionExpiry = null;
                $mood->suppressionSourceCardId = null;
            }
        }
    }

    /** Clears every suppression that only lasts "until the end of this round". */
    public function clearEndOfRoundSuppressions(): void
    {
        foreach ($this->moodsInPlay as $mood) {
            if ($mood->suppressionExpiry === 'end_of_round') {
                $mood->isSuppressed = false;
                $mood->suppressionExpiry = null;
                $mood->suppressionSourceCardId = null;
            }
        }
    }

    // --- per-mood effect state (modal choices, one-time value overrides) ---

    public function setEffectState(int $cardId, string $key, mixed $value): void
    {
        $this->moodInPlay($cardId)->effectState[$key] = $value;
    }

    public function effectState(int $cardId, string $key): mixed
    {
        return $this->moodsInPlay[$cardId]->effectState[$key] ?? null;
    }

    /** A one-time score change from an "after playing" effect (e.g. Dignity's "value becomes 5"), as opposed to a continuously recomputed "while in play" value. */
    public function setValueOverride(int $cardId, int $value): void
    {
        $this->setEffectState($cardId, 'valueOverride', $value);
    }

    // --- effective identity, color, and value ---

    /** Creativity plays as a copy of another card; every other mood is just itself. */
    public function effectiveCardId(int $cardId): int
    {
        return $this->moodsInPlay[$cardId]->copiedCardId ?? $cardId;
    }

    /**
     * A mood's color, honoring Imagination ("While in play, all moods are
     * the chosen color and no other colors") if one is in play. Imagination
     * itself never changes a card's *printed* color, only what other
     * color-counting effects perceive -- see the catalog row's own color
     * for the printed value.
     */
    public function colorOf(int $cardId): string
    {
        foreach ($this->moodsInPlay as $mood) {
            $row = $this->catalogRow($this->effectiveCardId($mood->cardId));
            if ($row['effectKey'] === 'imagination') {
                $color = $mood->effectState['color'] ?? null;
                if ($color !== null) {
                    return $color;
                }
            }
        }

        return $this->catalogRow($this->effectiveCardId($cardId))['color'];
    }

    /**
     * This mood's current score value: 0 if suppressed, its stored
     * one-time override if it has one, its live "while in play"
     * computation if it has that ability, otherwise its flat base value.
     */
    public function valueOf(int $cardId): int
    {
        $mood = $this->moodInPlay($cardId);
        if ($mood->isSuppressed) {
            return 0;
        }
        if (array_key_exists('valueOverride', $mood->effectState)) {
            return $mood->effectState['valueOverride'];
        }

        $row = $this->catalogRow($this->effectiveCardId($cardId));
        if ($row['hasWhileInPlay']) {
            return $this->registry->for($row['effectKey'])->computeValue($this, $cardId);
        }

        return $row['baseValue'];
    }

    // --- turn / plays-remaining ---

    public function currentPlayerId(): ?int
    {
        return $this->currentPlayerId;
    }

    public function startTurn(int $playerId, bool $hasHurtFeelings = false): void
    {
        $this->currentPlayerId = $playerId;
        $this->playGrants = array_fill(0, $hasHurtFeelings ? 2 : 1, null);
    }

    /**
     * Grants $count additional plays this turn. $restriction is null for
     * an unconditional grant (e.g. Charity); otherwise it's a descriptor
     * (see grantAllows()) that whatever card is played to use this grant
     * must satisfy -- e.g. Benevolence grants
     * ['type' => 'does_not_share_color_with_your_moods'].
     *
     * @param ?array{type: string, values?: int[]} $restriction
     */
    public function grantExtraPlay(int $count = 1, ?array $restriction = null): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->playGrants[] = $restriction;
        }
    }

    public function playsRemaining(): int
    {
        return count($this->playGrants);
    }

    /** @return array<int, ?array{type: string, values?: int[]}> */
    public function pendingPlayGrants(): array
    {
        return $this->playGrants;
    }

    /** Whether any outstanding grant this turn -- restricted or not -- would allow playing $cardId. */
    public function hasUsablePlayGrant(int $cardId, int $playerId): bool
    {
        foreach ($this->playGrants as $restriction) {
            if ($this->grantAllows($restriction, $cardId, $playerId)) {
                return true;
            }
        }

        return false;
    }

    /** Removes one outstanding grant that permits playing $cardId (see hasUsablePlayGrant()). */
    public function useGrantFor(int $cardId, int $playerId): void
    {
        foreach ($this->playGrants as $index => $restriction) {
            if ($this->grantAllows($restriction, $cardId, $playerId)) {
                unset($this->playGrants[$index]);
                $this->playGrants = array_values($this->playGrants);

                return;
            }
        }
    }

    /** Consumes one grant regardless of restriction -- for tests/setup that don't care which. MoodPlayService uses useGrantFor() instead, since it must consume a grant that actually permits the card being played. */
    public function consumePlay(): void
    {
        if ($this->playGrants !== []) {
            array_shift($this->playGrants);
        }
    }

    /** @param ?array{type: string, values?: int[]} $restriction */
    private function grantAllows(?array $restriction, int $cardId, int $playerId): bool
    {
        if ($restriction === null) {
            return true;
        }

        return match ($restriction['type']) {
            'shares_color_with_your_moods' => $this->sharesColorWithOwnMoods($cardId, $playerId),
            'does_not_share_color_with_your_moods' => !$this->sharesColorWithOwnMoods($cardId, $playerId),
            'base_value_in' => in_array($this->catalogRow($cardId)['baseValue'], $restriction['values'], true),
            default => throw new InvalidArgumentException("Unknown play grant restriction type '{$restriction['type']}'"),
        };
    }

    private function sharesColorWithOwnMoods(int $cardId, int $playerId): bool
    {
        $color = $this->colorOf($cardId);
        foreach ($this->moodsOwnedBy($playerId) as $mood) {
            if ($this->colorOf($mood->cardId) === $color) {
                return true;
            }
        }

        return false;
    }
}
