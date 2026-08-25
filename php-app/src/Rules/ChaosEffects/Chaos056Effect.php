<?php

declare(strict_types=1);

namespace MoodSwings\Rules\ChaosEffects;

use MoodSwings\Rules\AbstractChaosMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/**
 * chaos_056 (uncommon, after_playing): "You may choose an opponent's
 * mood. If you do, permanently reduce the chosen mood's value by this
 * mood's value. If the chosen mood's value would become less than 0, put
 * it in the discard pile instead." A DELTA ("reduce ... BY N"), not an
 * absolute override ("value BECOMES N") -- adjustChaosValueDelta() stacks
 * this with whatever the target's value already is (its own dice/alt
 * value included) rather than replacing it outright; see that method's
 * own docblock on BoardState.
 */
final class Chaos056Effect extends AbstractChaosMoodEffect
{
    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $targetCardId = $choices->int('mood_card_id');
        if ($targetCardId === null) {
            return;
        }
        if (!$state->isInPlay($targetCardId)) {
            throw new InvalidChoiceException("Card {$targetCardId} is not in play");
        }
        $opponentId = $state->ownerOf($targetCardId);
        if ($opponentId === $playerId || $state->isTeammate($playerId, $opponentId)) {
            throw new InvalidChoiceException('Only an opponent\'s mood can be chosen');
        }

        $reduction = $state->valueOf($cardId);
        $newValue = $state->valueOf($targetCardId) - $reduction;
        if ($newValue < 0) {
            $state->moveInPlayToDiscard($targetCardId);

            return;
        }

        $state->adjustChaosValueDelta($targetCardId, -$reduction);
    }
}
