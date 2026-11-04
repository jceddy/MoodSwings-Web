<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects\Custom;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/**
 * "After playing this mood -- If there are two or more <A> and/or <B> moods, you may <action> one
 * of them [with a value of N or less]." Distrust (to its player's hand), Disdain (discard pile),
 * Indignation (discard pile, value 4 or less), Vigilance (suppress for as long as you have this
 * mood). The target is optional; naming one while fewer than two such moods are in play is
 * refused rather than silently ignored.
 */
final class PairedColorTargetEffect extends AbstractMoodEffect
{
    public function __construct(
        private readonly string $colorA,
        private readonly string $colorB,
        private readonly string $action,
        private readonly ?int $maxValue = null,
    ) {
    }

    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $targetCardId = $choices->int('target_mood_id');
        if ($targetCardId === null) {
            return;
        }

        $colors = [$this->colorA, $this->colorB];
        if (CustomEffectSupport::countMoodsOfColors($state, $colors) < 2) {
            throw new InvalidChoiceException("There aren't two or more {$this->colorA} and/or {$this->colorB} moods");
        }
        if (!$state->isInPlay($targetCardId)) {
            throw new InvalidChoiceException("Card {$targetCardId} is not in play");
        }
        if (!in_array($state->colorOf($targetCardId), $colors, true)) {
            throw new InvalidChoiceException("The target must be a {$this->colorA} or {$this->colorB} mood");
        }
        if ($this->maxValue !== null && $state->valueOf($targetCardId) > $this->maxValue) {
            throw new InvalidChoiceException("The target must have a value of {$this->maxValue} or less");
        }

        match ($this->action) {
            'hand' => $state->moveInPlayToHand($targetCardId),
            'discard' => $state->moveInPlayToDiscard($targetCardId),
            'suppress' => $state->suppress($targetCardId, 'while_source_in_play', $cardId),
        };
    }
}
