<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/**
 * Encouragement: "After playing this mood, you may choose a mood with
 * dice in its lower left corner. While in play, the chosen mood uses the
 * dice total in its top right corner or lower left corner, whichever is
 * higher, to determine its value." "Dice" is the card's printed
 * alt_value (see BoardState::valueOf()'s dice-value handling); any mood
 * in play qualifies, not just the acting player's own. Tagged via the
 * well-known 'boostedMoodId' effectState key.
 */
final class EncouragementEffect extends AbstractMoodEffect
{
    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        if (!$choices->has('target_mood_id')) {
            return;
        }

        $targetCardId = $choices->requireInt('target_mood_id');
        if (!$state->isInPlay($targetCardId)) {
            throw new InvalidChoiceException("Card {$targetCardId} is not in play");
        }
        if ($state->catalogRow($state->effectiveCardId($targetCardId))['altValue'] === null) {
            throw new InvalidChoiceException("Card {$targetCardId} has no dice value to use");
        }

        $state->setEffectState($cardId, 'boostedMoodId', $targetCardId);
    }
}
