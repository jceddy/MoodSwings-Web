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
 */
interface ChaosMoodEffect
{
    public function computeValue(BoardState $state, int $cardId, int $incomingValue): int;

    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void;
}
