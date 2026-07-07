<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/**
 * Hesitation: "After playing this mood, choose one: put a red or green
 * mood into its player's hand, or put all red and green moods into their
 * players' hands." The same modal single-vs-mass shape as Guilt/Contempt,
 * mandatory like Guilt (no "you may").
 */
final class HesitationEffect extends AbstractMoodEffect
{
    private const QUALIFYING_COLORS = ['red', 'green'];

    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $mode = $choices->requireString('mode');

        $targets = match ($mode) {
            'single' => [$this->validatedSingleTarget($state, $choices)],
            'all' => $this->allQualifyingMoods($state),
            default => throw new InvalidChoiceException("Hesitation's mode must be 'single' or 'all'"),
        };

        foreach ($targets as $targetCardId) {
            $state->moveInPlayToHand($targetCardId);
        }
    }

    private function validatedSingleTarget(BoardState $state, PlayerChoices $choices): int
    {
        $targetCardId = $choices->requireInt('target_mood_id');
        if (!$state->isInPlay($targetCardId)) {
            throw new InvalidChoiceException("Card {$targetCardId} is not in play");
        }
        if (!in_array($state->colorOf($targetCardId), self::QUALIFYING_COLORS, true)) {
            throw new InvalidChoiceException('Hesitation can only target a red or green mood');
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
