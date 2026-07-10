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

    /** Whoever took the first turn of the current round (e.g. for Chivalry/Triumph) -- distinct from currentPlayerId, which changes every turn. */
    private ?int $roundFirstPlayerId = null;

    /** The current round's number (game_rounds.round_number), used to stamp newly-played moods with when they were played -- see moveHandToInPlay()/moveDiscardToInPlay() and the 'playedInRound' effectState key (Patience, Glee, Doubt). */
    private ?int $currentRoundNumber = null;

    /** Vulnerability: "this mood's value is 7 if a card was put into the discard pile this round." A single round-wide flag rather than anything card-specific, since it has to reflect any discard by any player -- see moveHandToDiscard()/moveInPlayToDiscard() and discardedThisRound(). */
    private bool $discardedThisRound = false;

    /** @var array<int, array<string, mixed>> cardId => effectState staged during payToPlayCost(), before the card exists as a MoodInPlay -- see stagePrePlayEffectState(). */
    private array $pendingEffectState = [];

    /**
     * @var array<int, ?array{type?: string, values?: int[], source?: string, onUseEffectState?: array<string, mixed>, sourceCardId?: int}> one entry per
     * outstanding "play an additional mood" grant this turn. null means
     * unconditional AND ungranted -- only ever startTurn()'s own base
     * allowance (1, or 2 with Hurt Feelings), never a card's grant; every
     * grantExtraPlay() call is instead a restriction array (even if
     * $restriction itself was omitted) once 'sourceCardId' is folded in,
     * since a granted extra play always has a card to attribute it to. A
     * restriction array's absent 'type' means the grant only covers a card
     * matching it (e.g. Benevolence's "if it doesn't share a color with
     * any of your moods") -- see grantAllows(). The 'onUseEffectState' key
     * (Gluttony/Insecurity) tags whichever specific card ends up consuming
     * this grant with effectState to apply once it's played -- see
     * useGrantFor() and MoodPlayService. 'sourceCardId' is purely a UI
     * reminder-text concern (see GameService::describePlayGrant()) --
     * grantAllows() itself never reads it.
     */
    private array $playGrants = [];

    /**
     * @var int[] card ids revealed by a purely random ($cardId chosen via
     * array_rand(), not any submitted choice) effect this play -- Paranoia/
     * Curiosity. Transient, never persisted by BoardStateRepository: it
     * exists only so GameService can read it back (consumeRevealedCardIds())
     * immediately before logging the play's own mood_played event, and fold
     * it into that event's details so a player who wasn't the one who
     * played the card can still find out what got revealed, e.g. via a
     * recent-plays panel. Not used for a card revealed by an explicit
     * choice (Doubt's own reveal, Intimidation's target's own answer) --
     * those are already visible in the play's own submitted choices/
     * pending-decision answer, already logged as-is.
     */
    private array $pendingRevealedCardIds = [];

    /**
     * @var array<int, array{card_id:int, from_zone:string, to_zone:string, from_player_id:?int, to_player_id:?int}>
     * Every zone transition recorded this play/response/scoring pass, so
     * GameService can fold them into whichever event it's about to log
     * (see consumeCardMoves()) -- this is what lets a card moved by a
     * random/effect-internal choice (Cruelty, Indecisiveness, Altruism) or
     * an opponent's own answer (Malice's color cascade, Disillusionment,
     * Suspicion) show up in game history exactly like a card named in the
     * acting player's own submitted choices already does, without every
     * effect class needing to log anything itself.
     *
     * Deliberately NOT recorded here: moveHandToInPlay()/moveDiscardToInPlay()
     * (always the card actually being played -- already implicit in the
     * mood_played/pending_decision_created event's own card_id, so adding
     * it again here would just repeat the same fact on every single play),
     * and drawCard() (unlike every other zone this class moves cards
     * through, a hand a card is DRAWN into was never previously public --
     * recording it would leak which card a player drew to every other
     * player's history, something no other move recorded here does, since
     * every other source zone -- play, the discard pile, or a hand a card
     * is LEAVING via an effect -- is either already public or already
     * loggable the same way a submitted choice already is).
     */
    private array $pendingCardMoves = [];

    /**
     * @var array<int, array{card_id:int, from_player_id:int, to_player_id:int}>
     * Every ownership change recorded this play/response/scoring pass, via
     * the same consume-before-logging convention as $pendingCardMoves --
     * see consumeOwnershipChanges(). A mood already sitting in play never
     * has anything hidden about who owns it, so unlike $pendingCardMoves
     * this needs no zone-based exceptions: every giveInPlayToPlayer() call
     * is recorded, whether it's a card's own effect (Guile, Instability,
     * Avoidance, Chaos), a temporary "give it back later" swap (Arrogance,
     * Betrayal, Recklessness), or that swap's own later reversal.
     */
    private array $pendingOwnershipChanges = [];

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

    public function isInDiscardPile(int $cardId): bool
    {
        return in_array($cardId, $this->discard, true);
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
        $this->moodsInPlay[$cardId] = new MoodInPlay($cardId, $playerId, $copiedCardId, effectState: $this->initialEffectState($cardId, 'hand'));
    }

    /** Harmony/Grief/Angst: plays a mood "from the discard pile" instead of from hand -- see BoardState::$playGrants' 'source' key and MoodPlayService. */
    public function moveDiscardToInPlay(int $playerId, int $cardId, ?int $copiedCardId = null): void
    {
        $this->removeFromDiscard($cardId);
        $this->moodsInPlay[$cardId] = new MoodInPlay($cardId, $playerId, $copiedCardId, effectState: $this->initialEffectState($cardId, 'discard'));
    }

    /**
     * 'playedFromZone' persists (unlike $pendingCardMoves/$pendingRevealedCardIds,
     * which only ever need to survive to the end of the single request that
     * fired them) as ordinary effectState -- GameService reads it back to
     * say e.g. "played Harmony from discard" even for a play that pauses on
     * a RequiresOpponentDecision and only actually finishes several requests
     * later, once the mood is long since already sitting in play.
     *
     * @return array<string, mixed>
     */
    private function initialEffectState(int $cardId, string $fromZone): array
    {
        return ['playedFromZone' => $fromZone, ...$this->playedInRoundTag(), ...$this->consumeStagedEffectState($cardId)];
    }

    /** @return array<string, mixed> */
    private function playedInRoundTag(): array
    {
        return $this->currentRoundNumber !== null ? ['playedInRound' => $this->currentRoundNumber] : [];
    }

    /**
     * Stashes an effectState key/value for $cardId to apply the moment it
     * actually enters play -- for a "to play this card" cost that needs to
     * capture something (e.g. Bliss recording the color of the card its
     * cost discards) before the card exists as a MoodInPlay to attach
     * effectState to normally; payToPlayCost() always runs before the
     * card is moved into play. Consumed (and cleared) by
     * moveHandToInPlay()/moveDiscardToInPlay() within that same call, so
     * nothing here needs to survive being reloaded from the database.
     */
    public function stagePrePlayEffectState(int $cardId, string $key, mixed $value): void
    {
        $this->pendingEffectState[$cardId][$key] = $value;
    }

    /** @return array<string, mixed> */
    private function consumeStagedEffectState(int $cardId): array
    {
        $staged = $this->pendingEffectState[$cardId] ?? [];
        unset($this->pendingEffectState[$cardId]);

        return $staged;
    }

    public function moveInPlayToDiscard(int $cardId): void
    {
        $this->moodInPlay($cardId);
        unset($this->moodsInPlay[$cardId]);
        $this->discard[] = $cardId;
        $this->discardedThisRound = true;
        $this->recordMove($cardId, 'play', 'discard');
        $this->cascadeMoodLeavingPlay($cardId);
    }

    public function moveInPlayToHand(int $cardId): void
    {
        $owner = $this->moodInPlay($cardId)->ownerId;
        unset($this->moodsInPlay[$cardId]);
        $this->hands[$owner][] = $cardId;
        $this->recordMove($cardId, 'play', 'hand', toPlayerId: $owner);
        $this->cascadeMoodLeavingPlay($cardId);
    }

    /** Regret: takes a mood directly into $newOwnerId's hand regardless of who actually owns it -- distinct from moveInPlayToHand(), which always returns a mood to its own owner's hand. */
    public function moveInPlayToPlayersHand(int $cardId, int $newOwnerId): void
    {
        $this->moodInPlay($cardId);
        unset($this->moodsInPlay[$cardId]);
        $this->hands[$newOwnerId][] = $cardId;
        $this->recordMove($cardId, 'play', 'hand', toPlayerId: $newOwnerId);
        $this->cascadeMoodLeavingPlay($cardId);
    }

    public function moveInPlayToBottomOfDeck(int $cardId): void
    {
        $this->moodInPlay($cardId);
        unset($this->moodsInPlay[$cardId]);
        $this->deck[] = $cardId;
        $this->recordMove($cardId, 'play', 'deck');
        $this->cascadeMoodLeavingPlay($cardId);
    }

    /**
     * Runs whenever $cardId itself (not just some other mood it suppressed
     * or stole) leaves play: lifts any suppression it was the source of
     * (see clearSuppressionsFrom()) and returns any mood tagged as "give
     * this back if you still have it when I leave play" (Arrogance) to its
     * original owner, provided the Arrogance player still actually holds
     * it -- see ArrogranceEffect.
     */
    private function cascadeMoodLeavingPlay(int $cardId): void
    {
        $this->clearSuppressionsFrom($cardId);

        foreach ($this->moodsInPlay as $mood) {
            $stolen = $mood->effectState['returnsToOwnerIfCardLeavesPlay'] ?? null;
            if ($stolen === null || $stolen['sourceCardId'] !== $cardId) {
                continue;
            }

            $this->clearEffectState($mood->cardId, 'returnsToOwnerIfCardLeavesPlay');
            if ($mood->ownerId === $stolen['heldByPlayerId']) {
                $this->giveInPlayToPlayer($mood->cardId, $stolen['ownerId']);
            }
        }
    }

    public function moveHandToDiscard(int $playerId, int $cardId): void
    {
        $this->removeFromHand($playerId, $cardId);
        $this->discard[] = $cardId;
        $this->discardedThisRound = true;
        $this->recordMove($cardId, 'hand', 'discard', fromPlayerId: $playerId);
    }

    public function moveHandToBottomOfDeck(int $playerId, int $cardId): void
    {
        $this->removeFromHand($playerId, $cardId);
        $this->deck[] = $cardId;
        $this->recordMove($cardId, 'hand', 'deck', fromPlayerId: $playerId);
    }

    public function moveDiscardToHand(int $playerId, int $cardId): void
    {
        $this->removeFromDiscard($cardId);
        $this->hands[$playerId][] = $cardId;
        $this->recordMove($cardId, 'discard', 'hand', toPlayerId: $playerId);
    }

    /** Altruism: shuffles the remainder of the discard pile onto the bottom of the deck. */
    public function moveDiscardToBottomOfDeck(int $cardId): void
    {
        $this->removeFromDiscard($cardId);
        $this->deck[] = $cardId;
        $this->recordMove($cardId, 'discard', 'deck');
    }

    public function giveInPlayToPlayer(int $cardId, int $newOwnerId): void
    {
        $oldOwnerId = $this->moodInPlay($cardId)->ownerId;
        $this->moodInPlay($cardId)->ownerId = $newOwnerId;
        $this->recordOwnershipChange($cardId, $oldOwnerId, $newOwnerId);
    }

    /** Fascination: hands a card directly from one player's hand to another's (e.g. "give it to another player"), rather than routing it through discard/deck. */
    public function giveHandCardToPlayer(int $fromPlayerId, int $toPlayerId, int $cardId): void
    {
        $this->removeFromHand($fromPlayerId, $cardId);
        $this->hands[$toPlayerId][] = $cardId;
        $this->recordMove($cardId, 'hand', 'hand', fromPlayerId: $fromPlayerId, toPlayerId: $toPlayerId);
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

    /** @param array<int, ?array{type?: string, values?: int[], source?: string, onUseEffectState?: array<string, mixed>}> $playGrants */
    public function restoreTurnState(?int $currentPlayerId, array $playGrants, ?int $roundFirstPlayerId, ?int $currentRoundNumber = null, bool $discardedThisRound = false): void
    {
        $this->currentPlayerId = $currentPlayerId;
        $this->playGrants = $playGrants;
        $this->roundFirstPlayerId = $roundFirstPlayerId;
        $this->currentRoundNumber = $currentRoundNumber;
        $this->discardedThisRound = $discardedThisRound;
    }

    /** Vulnerability: whether any card has been put into the discard pile so far this round -- see moveHandToDiscard()/moveInPlayToDiscard(). */
    public function discardedThisRound(): bool
    {
        return $this->discardedThisRound;
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

    /**
     * The reverse of a suppressed mood's own suppressionSourceCardId --
     * every mood currently suppressed *by* $sourceCardId. Purely a UI
     * reminder-text lookup (see GameService's "affecting" field on each
     * in-play card's serialization): the target's own suppressionSourceCardId
     * already drives the actual suppression, this just answers it from the
     * source's side.
     *
     * @return int[]
     */
    public function suppressedByCardId(int $sourceCardId): array
    {
        $result = [];
        foreach ($this->moodsInPlay as $cardId => $mood) {
            if ($mood->isSuppressed && $mood->suppressionSourceCardId === $sourceCardId) {
                $result[] = $cardId;
            }
        }

        return $result;
    }

    /** See $pendingRevealedCardIds' own docblock. Called by Paranoia/Curiosity at the point they pick their random hand card. */
    public function recordRevealedCard(int $cardId): void
    {
        $this->pendingRevealedCardIds[] = $cardId;
    }

    /**
     * Returns and clears every card id recorded via recordRevealedCard()
     * since the last call -- GameService calls this immediately before
     * logging a play's own mood_played event, so it's always scoped to
     * exactly the play (or Duplicity-repeated plays) that just resolved.
     *
     * @return int[]
     */
    public function consumeRevealedCardIds(): array
    {
        $ids = $this->pendingRevealedCardIds;
        $this->pendingRevealedCardIds = [];

        return $ids;
    }

    private function recordMove(int $cardId, string $fromZone, string $toZone, ?int $fromPlayerId = null, ?int $toPlayerId = null): void
    {
        $this->pendingCardMoves[] = [
            'card_id' => $cardId,
            'from_zone' => $fromZone,
            'to_zone' => $toZone,
            'from_player_id' => $fromPlayerId,
            'to_player_id' => $toPlayerId,
        ];
    }

    /**
     * Returns and clears every move recorded via recordMove() since the
     * last call -- see $pendingCardMoves' own docblock. Mirrors
     * consumeRevealedCardIds()'s own call convention: GameService calls
     * this immediately before logging whichever event this play/response/
     * scoring pass is about to produce.
     *
     * @return array<int, array{card_id:int, from_zone:string, to_zone:string, from_player_id:?int, to_player_id:?int}>
     */
    public function consumeCardMoves(): array
    {
        $moves = $this->pendingCardMoves;
        $this->pendingCardMoves = [];

        return $moves;
    }

    private function recordOwnershipChange(int $cardId, int $fromPlayerId, int $toPlayerId): void
    {
        $this->pendingOwnershipChanges[] = [
            'card_id' => $cardId,
            'from_player_id' => $fromPlayerId,
            'to_player_id' => $toPlayerId,
        ];
    }

    /**
     * Returns and clears every ownership change recorded via
     * giveInPlayToPlayer() since the last call -- see
     * $pendingOwnershipChanges' own docblock. Same consume-before-logging
     * convention as consumeCardMoves()/consumeRevealedCardIds().
     *
     * @return array<int, array{card_id:int, from_player_id:int, to_player_id:int}>
     */
    public function consumeOwnershipChanges(): array
    {
        $changes = $this->pendingOwnershipChanges;
        $this->pendingOwnershipChanges = [];

        return $changes;
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

    /** Unsets a specific effectState key so a one-shot marker (e.g. an after-scoring tag) doesn't reapply next round once it's been resolved. */
    public function clearEffectState(int $cardId, string $key): void
    {
        unset($this->moodInPlay($cardId)->effectState[$key]);
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

        // Encouragement/Idealism: "uses the dice total in its top right
        // corner or lower left corner, whichever is higher, to determine
        // its value" -- alt_value is the printed "dice" number, so this
        // overrides the card's own computed value entirely (rather than
        // just adding to it) for as long as either card applies.
        if ($row['altValue'] !== null && $this->diceValueBoosterCardId($cardId) !== null) {
            return max($row['baseValue'], $row['altValue']);
        }

        if ($row['hasWhileInPlay']) {
            return $this->registry->for($row['effectKey'])->computeValue($this, $cardId);
        }

        return $row['baseValue'];
    }

    /**
     * The card id of the Encouragement or Idealism currently applying
     * $cardId's dice value, or null if neither currently does. Encouragement
     * tags one specific chosen mood (its 'boostedMoodId' effectState key);
     * Idealism blankets every mood its owner controls. A mood without a
     * printed alt_value ("dice") is never affected by either, regardless of
     * whether it's targeted/owned, but this method doesn't check that
     * itself -- valueOf() only calls it once it's already confirmed $cardId
     * has one, and GameService's "affecting"/"affected by" reminder-text
     * lookup (see its in_play serialization) checks has_dice_value
     * separately before showing anything either direction.
     */
    public function diceValueBoosterCardId(int $cardId): ?int
    {
        $ownerId = $this->moodInPlay($cardId)->ownerId;
        foreach ($this->moodsInPlay as $boosterCardId => $mood) {
            $effectKey = $this->catalogRow($this->effectiveCardId($mood->cardId))['effectKey'];
            if ($effectKey === 'encouragement' && ($mood->effectState['boostedMoodId'] ?? null) === $cardId) {
                return $boosterCardId;
            }
            if ($effectKey === 'idealism' && $mood->ownerId === $ownerId) {
                return $boosterCardId;
            }
        }

        return null;
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
     * Marks $playerId as whoever took the first turn of the current round
     * (e.g. for Chivalry/Triumph) -- called once per round, unlike
     * startTurn() which happens every turn. $roundNumber is optional since
     * most tests don't care about it; production code instead gets it via
     * restoreTurnState(), since this method is never called outside tests
     * (GameService/BoardStateRepository populate round state directly from
     * the database).
     */
    public function startRound(int $playerId, ?int $roundNumber = null): void
    {
        $this->roundFirstPlayerId = $playerId;
        $this->currentRoundNumber = $roundNumber;
    }

    public function currentRoundNumber(): ?int
    {
        return $this->currentRoundNumber;
    }

    /**
     * Colors banned from being played this round by any player, per Doubt's
     * "during the next round, players can't play moods that share a color
     * with any of the revealed cards" -- active for exactly the round
     * immediately after Doubt's own 'playedInRound', using the same tag
     * moveHandToInPlay()/moveDiscardToInPlay() stamp on every mood (see
     * DoubtEffect).
     *
     * @return string[]
     */
    public function bannedColorsThisRound(): array
    {
        $banned = [];
        foreach ($this->moodsInPlay as $mood) {
            $bannedColors = $mood->effectState['bannedColors'] ?? null;
            $playedInRound = $mood->effectState['playedInRound'] ?? null;
            if ($bannedColors !== null && $playedInRound !== null && $this->currentRoundNumber === $playedInRound + 1) {
                array_push($banned, ...$bannedColors);
            }
        }

        return array_values(array_unique($banned));
    }

    public function roundFirstPlayerId(): ?int
    {
        return $this->roundFirstPlayerId;
    }

    /**
     * Whoever should go first *next* round, per a currently-in-play mood
     * that overrides the normal "round winner goes first" rule. Two
     * distinct effectState keys feed this, since they have different
     * lifetimes: Honor's 'firstPlayerOverride' is perpetual -- "while in
     * play, the chosen player goes first each round" -- so it's simply
     * never cleared, and is automatically self-correcting if Honor later
     * leaves play, with nothing separate to clean up. Awe's
     * 'oneTimeFirstPlayerOverride' only covers the round immediately after
     * it's played ("you choose which player goes first next round"), so
     * GameService::skipScoringAndAdvance() explicitly clears it once
     * consumed -- otherwise Awe staying in play would keep overriding
     * every future round too, which its text doesn't support. GameService
     * checks this when starting a new round; the first override found
     * wins (no defined tie-break if both happen to be in play at once).
     */
    public function firstPlayerOverride(): ?int
    {
        foreach ($this->moodsInPlay as $mood) {
            if (array_key_exists('firstPlayerOverride', $mood->effectState)) {
                return $mood->effectState['firstPlayerOverride'];
            }
            if (array_key_exists('oneTimeFirstPlayerOverride', $mood->effectState)) {
                return $mood->effectState['oneTimeFirstPlayerOverride'];
            }
        }

        return null;
    }

    /**
     * Grants $count additional plays this turn. $restriction is null for
     * an otherwise-unconditional grant (e.g. Charity); otherwise it's a
     * descriptor (see grantAllows()) that whatever card is played to use
     * this grant must satisfy -- e.g. Benevolence grants
     * ['type' => 'does_not_share_color_with_your_moods']. $sourceCardId is
     * every effect's own $cardId, folded into the stored descriptor as
     * 'sourceCardId' regardless of whether $restriction itself was given,
     * purely so GameService can later name which card is responsible for
     * this specific outstanding play (see describePlayGrant()) -- the one
     * caller that doesn't pass it is BoardStateTest's own generic
     * "does a grant exist at all" test, and startTurn()'s own base
     * allowance is never granted through this method at all.
     *
     * @param ?array{type?: string, values?: int[], source?: string} $restriction
     */
    public function grantExtraPlay(int $count = 1, ?array $restriction = null, ?int $sourceCardId = null): void
    {
        if ($sourceCardId !== null) {
            $restriction = [...($restriction ?? []), 'sourceCardId' => $sourceCardId];
        }

        for ($i = 0; $i < $count; $i++) {
            $this->playGrants[] = $restriction;
        }
    }

    public function playsRemaining(): int
    {
        return count($this->playGrants);
    }

    /** @return array<int, ?array{type?: string, values?: int[], source?: string, onUseEffectState?: array<string, mixed>}> */
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

    /**
     * Removes one outstanding grant that permits playing $cardId (see
     * hasUsablePlayGrant()), returning the restriction descriptor it
     * consumed (or null for an unconditional grant, or if none matched) so
     * callers can react to a grant-specific tag -- see the
     * 'onUseEffectState' key.
     *
     * @return ?array{type?: string, values?: int[], source?: string, onUseEffectState?: array<string, mixed>}
     */
    public function useGrantFor(int $cardId, int $playerId): ?array
    {
        foreach ($this->playGrants as $index => $restriction) {
            if ($this->grantAllows($restriction, $cardId, $playerId)) {
                unset($this->playGrants[$index]);
                $this->playGrants = array_values($this->playGrants);

                return $restriction;
            }
        }

        return null;
    }

    /** Consumes one grant regardless of restriction -- for tests/setup that don't care which. MoodPlayService uses useGrantFor() instead, since it must consume a grant that actually permits the card being played. */
    public function consumePlay(): void
    {
        if ($this->playGrants !== []) {
            array_shift($this->playGrants);
        }
    }

    /**
     * @param ?array{type?: string, values?: int[], source?: string} $restriction
     */
    private function grantAllows(?array $restriction, int $cardId, int $playerId): bool
    {
        // 'source' defaults to 'hand'; Harmony/Grief/Angst grant plays
        // sourced from the discard pile instead, so a hand-sourced grant
        // must not match a card that's actually sitting in the discard
        // pile, and vice versa -- except Melancholy, which lets its owner
        // treat the whole discard pile as part of their hand for every
        // play (not just a dedicated bonus one), so a hand-sourced grant
        // is allowed to match a discard-pile card for that specific player.
        $source = $restriction['source'] ?? 'hand';
        $cardInDiscard = $this->isInDiscardPile($cardId);
        if ($source === 'discard') {
            if (!$cardInDiscard) {
                return false;
            }
        } elseif ($cardInDiscard && !$this->playerHasMoodInPlay($playerId, 'melancholy')) {
            return false;
        }

        if ($restriction === null || !isset($restriction['type'])) {
            return true;
        }

        return match ($restriction['type']) {
            'shares_color_with_your_moods' => $this->sharesColorWithOwnMoods($cardId, $playerId),
            'does_not_share_color_with_your_moods' => !$this->sharesColorWithOwnMoods($cardId, $playerId),
            'base_value_in' => in_array($this->catalogRow($cardId)['baseValue'], $restriction['values'], true),
            // Intimidation: the grant only ever covers the one specific
            // card it revealed, not any card sharing some trait with it.
            'specific_card_ids' => in_array($cardId, $restriction['values'], true),
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

    /** Whether $playerId currently has a mood with effect_key $effectKey in play (e.g. Melancholy, or GameService checking for Hope/Grace/Stubbornness at the start of a turn). */
    public function playerHasMoodInPlay(int $playerId, string $effectKey): bool
    {
        return $this->countMoodsInPlayWithEffectiveKey($playerId, $effectKey) > 0;
    }

    /**
     * How many of $playerId's own in-play moods have $effectKey as their
     * EFFECTIVE (copy-aware) effect key -- e.g. a real Duplicity plus a
     * Creativity currently copying Duplicity both count. Most callers only
     * need playerHasMoodInPlay()'s boolean; Duplicity's repeat-offer needs
     * the actual count, since each such mood grants its own independent
     * "may repeat" decision (see MoodPlayService::continueAfterPlayingChain()).
     */
    public function countMoodsInPlayWithEffectiveKey(int $playerId, string $effectKey): int
    {
        $count = 0;
        foreach ($this->moodsOwnedBy($playerId) as $mood) {
            if ($this->catalogRow($this->effectiveCardId($mood->cardId))['effectKey'] === $effectKey) {
                $count++;
            }
        }

        return $count;
    }
}
