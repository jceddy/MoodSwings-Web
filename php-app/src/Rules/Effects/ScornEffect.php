<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/**
 * Scorn: "After playing this mood, suppress any mood until the end of
 * this round. While in play, each time you play another mood, you may
 * suppress a mood that shares a color with it until the end of this
 * round." The first sentence is mandatory (no "may"); the second reacts
 * to Scorn's own owner playing a different mood -- see
 * MoodEffect::reactToAnotherPlay() and MoodPlayService.
 */
final class ScornEffect extends AbstractMoodEffect
{
    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $targetCardId = $choices->requireInt('target_mood_id');
        if (!$state->isInPlay($targetCardId)) {
            throw new InvalidChoiceException("Card {$targetCardId} is not in play");
        }

        $state->suppress($targetCardId, 'end_of_round', $cardId);
    }

    public function reactToAnotherPlay(BoardState $state, int $reactorCardId, int $playedCardId, int $playerId, PlayerChoices $choices): void
    {
        if (!$choices->has('scorn_suppress_target')) {
            return;
        }

        $targetCardId = $choices->requireInt('scorn_suppress_target');
        if (!$state->isInPlay($targetCardId)) {
            throw new InvalidChoiceException("Card {$targetCardId} is not in play");
        }
        if ($state->colorOf($targetCardId) !== $state->colorOf($playedCardId)) {
            throw new InvalidChoiceException("Scorn can only suppress a mood sharing a color with card {$playedCardId}");
        }

        $state->suppress($targetCardId, 'end_of_round', $reactorCardId);
    }
}
