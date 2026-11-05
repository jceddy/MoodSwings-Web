<?php

declare(strict_types=1);

namespace MoodSwings\Rules;

/**
 * Implemented by moods whose ability fires "at the start of each of your turns" (Brooding,
 * Covetousness, FOMO, Jealousy, Martyrdom, Neglect, ...). GameService::runTurnStartTriggers() runs
 * every such mood its owner has, one after another in the order they entered play, each time a turn
 * begins -- see that method for the turn-start entry points. A trigger that needs a player's input
 * pauses the round exactly like any other pending decision (the same game_pending_decision_batches
 * rows, decision type 'turn_start_trigger'); once it's answered the next trigger runs, and only then
 * can the player act.
 *
 * Like RequiresOpponentDecision, implementers still extend AbstractMoodEffect: it supplies the no-op
 * afterPlaying()/computeValue() defaults, and a turn-start trigger never goes through afterPlaying().
 */
interface TurnStartEffect
{
    /**
     * The decisions to ask before this trigger can resolve, or null when the trigger is automatic and
     * needs nobody's input (Volatility, Maturity). An empty array means there is nothing to decide right
     * now (an optional trigger with no legal choice) and the trigger is skipped. Must not mutate $state.
     *
     * @return ?PendingDecisionRequest[]
     */
    public function turnStartRequests(BoardState $state, int $cardId, int $playerId): ?array;

    /**
     * Applies the trigger once every request from turnStartRequests() (or from an earlier call to this
     * method) has been answered -- $answers is keyed by each request's own key, and is empty for an
     * automatic trigger. Returns any FOLLOW-UP decisions that only become askable now (Brooding's "then
     * discard a card from your hand", once the card is actually in it), or [] when fully resolved.
     *
     * @param array<string, PlayerChoices> $answers
     * @return PendingDecisionRequest[]
     */
    public function applyTurnStart(BoardState $state, int $cardId, int $playerId, array $answers): array;
}
