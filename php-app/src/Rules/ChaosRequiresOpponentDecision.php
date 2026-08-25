<?php

declare(strict_types=1);

namespace MoodSwings\Rules;

/**
 * The chaos-effect counterpart to RequiresOpponentDecision -- implemented
 * by the handful of chaos effects whose afterPlaying() needs a real
 * decision from a player OTHER than the one who played the card (or from
 * every qualifying player, including the acting player themselves), rather
 * than the uniformly-random simplification ChaosMoodEffect's own class
 * docblock otherwise documents as this pool's deliberate default. That
 * default was a maintainer-confirmed simplification when Chaos Draft first
 * shipped (issue #405) -- reversed here, per a later maintainer ruling
 * (issue #405 follow-up, reported live: "the other player should choose
 * the card from their hand to give to me -- it should not be random"),
 * for the specific chaos effects whose printed text has an exact
 * identically-shaped printed-card precedent already implementing
 * RequiresOpponentDecision -- see each implementer's own docblock for its
 * precedent. Every OTHER chaos effect's own "you may choose..."/"each
 * player..." simplification is UNCHANGED and still deliberate, including
 * the reactive hooks (onMoodDiscarded/onMoodPlayed/afterScoring/etc.),
 * which have no request-scoped PlayerChoices to pause with at all and so
 * are out of scope for this interface entirely.
 *
 * Deliberately standalone rather than extending ChaosMoodEffect, mirroring
 * RequiresOpponentDecision's own reasoning exactly: every implementer
 * still extends AbstractChaosMoodEffect (inheriting the usual no-op
 * afterPlaying() default), but that inherited afterPlaying() is never
 * actually called for an implementer of this interface --
 * MoodPlayService::resolveAttachedChaosAfterPlaying() checks `instanceof
 * ChaosRequiresOpponentDecision` first and always routes through
 * pendingDecisionsFor()/resolveDecisions() instead.
 *
 * $choices here is always the attached effect's own namespaced sub-bag
 * (PlayerChoices::sub('chaos'), already unwrapped by the caller -- see
 * ChaosMoodEffect's own class docblock), matching exactly what
 * afterPlaying() itself would have received had this effect not
 * implemented this interface.
 */
interface ChaosRequiresOpponentDecision
{
    /**
     * Returns the queue of decisions to ask other players for, in the
     * order they should be asked. Must not mutate $state, for the same
     * "safe to call speculatively" reason RequiresOpponentDecision's own
     * pendingDecisionsFor() documents. Returns [] when this specific play
     * doesn't actually need anyone's input (e.g. the target was declined,
     * or has nothing qualifying to give) -- MoodPlayService then treats it
     * exactly like an ordinary immediate no-op afterPlaying().
     *
     * @return PendingDecisionRequest[]
     */
    public function pendingDecisionsFor(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): array;

    /**
     * Called once every decision from pendingDecisionsFor() has an answer,
     * in the same order -- performs the mutations that used to happen
     * right after the array_rand()/shuffle() call this interface replaces.
     * $answers is keyed by each PendingDecisionRequest's own $key, one
     * PlayerChoices per answer.
     *
     * Returns any FOLLOW-UP decisions that only become askable now that
     * this round's mutations have actually been applied -- mirrors
     * RequiresOpponentDecision::resolveDecisions()'s own contract exactly;
     * none of this interface's own implementers currently need one, but
     * the shape is kept identical for consistency and in case a future
     * chaos effect does.
     *
     * @param array<string, PlayerChoices> $answers
     * @return PendingDecisionRequest[]
     */
    public function resolveDecisions(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices, array $answers): array;
}
