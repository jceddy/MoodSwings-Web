<?php

declare(strict_types=1);

namespace MoodSwings\Rules\ChaosEffects;

use MoodSwings\Rules\AbstractChaosMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/** chaos_073 (rare, after_playing): "You may choose two other moods. If the two chosen moods share a color or have the same value, put them into the discard pile." */
final class Chaos073Effect extends AbstractChaosMoodEffect
{
    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $targetCardIds = array_unique($choices->ints('mood_card_ids'));
        if ($targetCardIds === []) {
            return;
        }
        if (count($targetCardIds) !== 2) {
            throw new InvalidChoiceException('Choose exactly two moods');
        }

        [$firstCardId, $secondCardId] = array_values($targetCardIds);
        foreach ([$firstCardId, $secondCardId] as $targetCardId) {
            if ($targetCardId === $cardId || !$state->isInPlay($targetCardId)) {
                throw new InvalidChoiceException("Card {$targetCardId} is not a valid mood to choose");
            }
        }

        $sameColor = $state->colorOf($firstCardId) === $state->colorOf($secondCardId);
        $sameValue = $state->valueOf($firstCardId) === $state->valueOf($secondCardId);
        if (!$sameColor && !$sameValue) {
            return;
        }

        $state->moveInPlayToDiscard($firstCardId);
        $state->moveInPlayToDiscard($secondCardId);
    }
}
