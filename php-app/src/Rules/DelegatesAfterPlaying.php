<?php

declare(strict_types=1);

namespace MoodSwings\Rules;

/**
 * Implemented by the effects whose whole job is to make ANOTHER card's after-playing effect happen again -- Optimism (one of your
 * moods in play) and Admiration (a mood in the discard pile). Like Duplicity's repeat, running an effect needs the registry,
 * which no MoodEffect has, so MoodPlayService does it: it asks the effect for the plan below, collects the fresh choices the
 * repeated effect needs from the acting player (a pending decision, like Duplicity's repeat offer), then runs that effect --
 * pausing again, if it needs someone's decision, with the original card still the one the play belongs to -- before carrying on
 * with the original card's own chain. The repeat is not a play, so Duplicity, Wistfulness and "each time you play another
 * mood" reactions don't treat it as one.
 */
interface DelegatesAfterPlaying
{
    /**
     * @return ?array{effectKey: string, sourceCardId: int, fields: array<int, array<string, mixed>>} null when there is nothing to
     *     run (no choice made, or the chosen mood has no after-playing effect); otherwise the effect to run, the card it runs
     *     as ("this mood": the repeated mood itself for Optimism, the Admiration just played for Admiration) and the choice
     *     fields the repeat needs (empty if none).
     */
    public function delegatedInvocation(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): ?array;
}
