<?php

declare(strict_types=1);

namespace MoodSwings\Rules\ChaosEffects;

use MoodSwings\Rules\AbstractChaosMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/** chaos_075 (common, after_playing): "You may put an opponent's mood with value less than this mood's value into the discard pile." */
final class Chaos075Effect extends AbstractChaosMoodEffect
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
        if ($state->valueOf($targetCardId) >= $state->valueOf($cardId)) {
            throw new InvalidChoiceException("Card {$targetCardId} does not have a lower value than this mood");
        }

        $state->moveInPlayToDiscard($targetCardId);
    }
}
