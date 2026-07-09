<?php

declare(strict_types=1);

namespace MoodSwings\Rules;

/**
 * Implemented by the handful of effects whose afterPlaying() needs a real
 * decision from a player OTHER than the one who played the card --
 * Arrogance, Compulsion, Instability, Intimidation, Malice, Suspicion,
 * Disillusionment -- or from EVERY qualifying player, including the
 * acting player themselves -- Avoidance, Confusion, Fury. MoodPlayService
 * checks for this interface instead of calling afterPlaying() directly;
 * every other effect (the vast majority) is untouched by this and just
 * implements MoodEffect as before.
 *
 * Deliberately standalone rather than extending MoodEffect: every
 * implementer still extends AbstractMoodEffect (so it inherits the usual
 * no-op afterPlaying() default), but that inherited afterPlaying() is
 * never actually called for these 7 -- MoodPlayService checks
 * `instanceof RequiresOpponentDecision` first and always routes through
 * pendingDecisionsFor()/resolveDecisions() instead, so there's no reason
 * to force an unused override of the base interface's method here too.
 */
interface RequiresOpponentDecision
{
    /**
     * Returns the queue of decisions to ask other players for, in the
     * order they should be asked. Must not mutate $state -- every one of
     * this interface's implementers' own logic up to the decision point is
     * already a pure read (validating $choices, computing candidates), so
     * this is safe to call speculatively. Returns [] when this specific
     * play doesn't actually need anyone's input (e.g. Arrogance's target
     * has no qualifying moods, or the acting player declined the optional
     * trigger entirely) -- MoodPlayService then treats it exactly like an
     * ordinary immediate no-op afterPlaying(), the same as today.
     *
     * @return PendingDecisionRequest[]
     */
    public function pendingDecisionsFor(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): array;

    /**
     * Called once every decision from pendingDecisionsFor() has an answer,
     * in the same order -- performs the mutations that used to happen
     * right after the array_rand() call this interface replaces. $answers
     * is keyed by each PendingDecisionRequest's own $key, one PlayerChoices
     * per answer (wrapping just that field's submitted value).
     *
     * @param array<string, PlayerChoices> $answers
     */
    public function resolveDecisions(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices, array $answers): void;
}
