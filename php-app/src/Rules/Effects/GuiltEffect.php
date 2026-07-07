<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/**
 * Guilt: "After playing this mood, choose one: suppress a black or red
 * mood for as long as you have this mood, or suppress all black and red
 * moods for as long as you have this mood." The first modal choice
 * between a single source-tied suppression and a mass one.
 */
final class GuiltEffect extends AbstractMoodEffect
{
    private const QUALIFYING_COLORS = ['black', 'red'];

    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $mode = $choices->requireString('mode');

        $targets = match ($mode) {
            'single' => [$this->validatedSingleTarget($state, $choices)],
            'all' => $this->allQualifyingMoods($state),
            default => throw new InvalidChoiceException("Guilt's mode must be 'single' or 'all'"),
        };

        foreach ($targets as $targetCardId) {
            $state->suppress($targetCardId, 'while_source_in_play', $cardId);
        }
    }

    private function validatedSingleTarget(BoardState $state, PlayerChoices $choices): int
    {
        $targetCardId = $choices->requireInt('target_mood_id');
        if (!$state->isInPlay($targetCardId)) {
            throw new InvalidChoiceException("Card {$targetCardId} is not in play");
        }
        if (!in_array($state->colorOf($targetCardId), self::QUALIFYING_COLORS, true)) {
            throw new InvalidChoiceException('Guilt can only target a black or red mood');
        }

        return $targetCardId;
    }

    /** @return int[] */
    private function allQualifyingMoods(BoardState $state): array
    {
        $targets = [];
        foreach ($state->moodsInPlay() as $mood) {
            if (in_array($state->colorOf($mood->cardId), self::QUALIFYING_COLORS, true)) {
                $targets[] = $mood->cardId;
            }
        }

        return $targets;
    }
}
