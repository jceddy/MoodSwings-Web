<?php

declare(strict_types=1);

namespace MoodSwings\Rules\ChaosEffects;

use MoodSwings\Rules\AbstractChaosMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/** chaos_080 (uncommon, after_playing): "You may put any number of moods with total value 5 or less into the discard pile." */
final class Chaos080Effect extends AbstractChaosMoodEffect
{
    private const MAX_TOTAL_VALUE = 5;

    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $targetCardIds = array_unique($choices->ints('mood_card_ids'));
        if ($targetCardIds === []) {
            return;
        }

        $total = 0;
        foreach ($targetCardIds as $targetCardId) {
            if (!$state->isInPlay($targetCardId)) {
                throw new InvalidChoiceException("Card {$targetCardId} is not in play");
            }
            $total += $state->valueOf($targetCardId);
        }
        if ($total > self::MAX_TOTAL_VALUE) {
            throw new InvalidChoiceException('Total value of chosen moods exceeds 5');
        }

        foreach ($targetCardIds as $targetCardId) {
            $state->moveInPlayToDiscard($targetCardId);
        }
    }
}
