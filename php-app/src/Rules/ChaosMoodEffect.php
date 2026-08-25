<?php

declare(strict_types=1);

namespace MoodSwings\Rules;

/**
 * A Chaos Draft effect's own gameplay behavior (issue #405), dispatched by
 * chaos_effects.effect_key -- the same two ability timings MoodEffect
 * itself has, minus "to play this card": the issue scopes the whole
 * effect pool to "never a 'to play this card' cost-type effect", so
 * there's no canPayToPlayCost()/payToPlayCost() pair to implement here.
 * See ChaosEffectRegistry's own docblock for how a card's attached effect
 * (game_cards.chaos_effect_id) is dispatched to an implementation of this
 * interface, and BoardState's own "Chaos Draft" section for exactly where
 * each hook is invoked.
 *
 * computeValue() takes an extra $incomingValue the plain MoodEffect
 * interface doesn't have (confirmed by the maintainer): a card carrying
 * both its own printed while-in-play ability AND an attached chaos effect
 * "stacks with (doesn't replace or override) the card's own printed
 * ability" per the issue, so the two compose as a PIPELINE rather than
 * either being computed in isolation -- the card's own printed ability
 * (or its flat base value, if it has none) always runs first and
 * produces a real value, which is then handed to the attached chaos
 * effect's own computeValue() as $incomingValue for it to further adjust
 * (an absolute override, a flat bonus, or a conditional swap all read
 * naturally from this one starting number). See BoardState::valueOf()'s
 * own docblock for exactly where this pipeline runs.
 *
 * afterPlaying() resolves as one simple, synchronous step once the
 * card's own base afterPlaying() (if any) has fully finished resolving --
 * confirmed by the maintainer as a deliberate simplification: it is NOT
 * woven into MoodPlayService's own Duplicity-repeat/opponent-decision-
 * pause/reactor-chain machinery the way a card's own registered
 * MoodEffect is, since every effect in the curated pool is a
 * player-declined-if-no-choices "you may ..." shape (the same simple,
 * single-request pattern the large majority of ordinary cards already
 * use) with no need to pause for an OPPONENT's own decision mid-effect.
 * Reads its own choices from a namespaced sub-bag (PlayerChoices::sub('chaos'),
 * see MoodPlayService's own Chaos Draft integration point) so its own
 * field keys can never collide with the base card's own.
 *
 * The remaining five hooks below cover the small minority of the 133-effect
 * pool whose printed text is a recurring "while in play" reaction to
 * something other than this card's own value or its own one-time play --
 * a per-turn/per-round grant, a board event (a mood entering play, being
 * discarded, or becoming suppressed), or a scoring-time bonus/reaction.
 * Every one of them has a no-op default in AbstractChaosMoodEffect, so an
 * effect that doesn't need one simply never overrides it -- exactly like
 * MoodEffect's own reactToAnotherPlay() is a narrow extra hook most cards
 * never touch. Each is dispatched from the SAME existing engine call site
 * a plain card's own identically-shaped ability already uses (turn start,
 * round start, a zone move, a suppression, or scoring) rather than any
 * new generic event bus -- see BoardState's "Chaos Draft" section and
 * GameService's own dispatch points for exactly where.
 */
interface ChaosMoodEffect
{
    public function computeValue(BoardState $state, int $cardId, int $incomingValue): int;

    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void;

    /**
     * Zero or more per-turn "you may play an additional mood" grants this
     * effect contributes at the start of $ownerId's own turn, mirroring
     * Hope/Grace/Stubbornness's own built-in perpetual grants -- see
     * GameService::computeFreshGrants(). An empty array (the Abstract
     * default) means "no grant this turn"; each entry is the same
     * restriction-descriptor shape BoardState::grantExtraPlay() itself
     * accepts. Also invoked once immediately, the very turn this card
     * itself enters play (see MoodPlayService's own Chaos Draft
     * integration point), for the handful of effects whose printed text
     * says "including the turn you play this mood."
     *
     * @return list<array{type?: string, values?: int[], source?: string}>
     */
    public function perpetualTurnStartGrants(BoardState $state, int $cardId, int $ownerId): array;

    /** Fires once at the start of every round, for every still-in-play mood carrying this effect -- e.g. a "put a token into play if you go first this round" ability. */
    public function roundStartHook(BoardState $state, int $cardId, int $ownerId): void;

    /** Fires once a mood (by ANY player, possibly this same card) finishes resolving its own full play -- covers "each time you/an opponent plays another mood" reactions. $ownerId is this effect's own card's owner; $playedByPlayerId/$playedCardId describe the play that just happened. */
    public function onMoodPlayed(BoardState $state, int $cardId, int $ownerId, int $playedByPlayerId, int $playedCardId): void;

    /** Fires once per mood moved from play to the discard pile (by any cause), for every other still-in-play mood carrying this effect -- $discardedValue is that mood's own value at the moment it left play, since it can no longer be read back afterward. */
    public function onMoodDiscarded(BoardState $state, int $cardId, int $ownerId, int $discardedCardId, int $discardedOwnerId, int $discardedValue): void;

    /** Fires once per mood newly suppressed, for every still-in-play mood carrying this effect. */
    public function onMoodSuppressed(BoardState $state, int $cardId, int $ownerId, int $suppressedCardId): void;

    /** Additional score points this effect contributes to $ownerId's own round score, added before the round's winner/Hurt Feelings holder are determined -- mirrors Enthusiasm/Passion's own scoring-time bonus (see RoundScorer's own docblock), simplified to always apply rather than pausing for a real accept/decline decision (see ChaosMoodEffect's own class docblock on why chaos effects never pause). */
    public function scoringBonus(BoardState $state, int $cardId, int $ownerId): int;

    /**
     * Fires once per still-in-play mood carrying this effect, after the
     * round's final scores/winner/lowest-scorer are already known --
     * covers "after scoring, if you won/have the lowest score, ..."
     * abilities that need more than the existing generic 'afterScoring'
     * self-tag (GameService::applyAfterScoringHooks()) already supports.
     *
     * @param array<int, int> $scores playerId => final score
     * @param int[] $winningGamePlayerIds
     */
    public function afterScoring(BoardState $state, int $cardId, int $ownerId, array $scores, array $winningGamePlayerIds, int $lowestScorePlayerId): void;
}
