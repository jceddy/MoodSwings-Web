<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/**
 * Contempt: "After playing this mood, you may choose one: put a green or
 * white mood into the discard pile, or put all green and white moods into
 * the discard pile." Like Guilt's modal single-vs-mass choice, but the
 * whole thing is optional here ("you may choose one") rather than
 * mandatory.
 */
final class ContemptEffect extends AbstractMoodEffect
{
    private const QUALIFYING_COLORS = ['green', 'white'];

    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $mode = $choices->string('mode');
        if ($mode === null) {
            return;
        }

        $targets = match ($mode) {
            'single' => [$this->validatedSingleTarget($state, $choices)],
            'all' => $this->allQualifyingMoods($state),
            default => throw new InvalidChoiceException("Contempt's mode must be 'single' or 'all'"),
        };

        foreach ($targets as $targetCardId) {
            $state->moveInPlayToDiscard($targetCardId);
        }
    }

    private function validatedSingleTarget(BoardState $state, PlayerChoices $choices): int
    {
        $targetCardId = $choices->requireInt('target_mood_id');
        if (!$state->isInPlay($targetCardId)) {
            throw new InvalidChoiceException("Card {$targetCardId} is not in play");
        }
        if (!in_array($state->colorOf($targetCardId), self::QUALIFYING_COLORS, true)) {
            throw new InvalidChoiceException('Contempt can only target a green or white mood');
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
