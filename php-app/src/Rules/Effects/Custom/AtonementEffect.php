<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects\Custom;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/** Atonement: "After playing this mood -- Choose a color. Put all other moods of the chosen color into the discard pile." Mandatory: the color is required (Atonement itself is never affected). */
final class AtonementEffect extends AbstractMoodEffect
{
    private const COLORS = ['white', 'blue', 'black', 'red', 'green'];

    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $color = $choices->requireString('color');
        if (!in_array($color, self::COLORS, true)) {
            throw new InvalidChoiceException("'{$color}' is not a color");
        }

        $targets = [];
        foreach ($state->moodsInPlay() as $mood) {
            if ($mood->cardId !== $cardId && $state->colorOf($mood->cardId) === $color) {
                $targets[] = $mood->cardId;
            }
        }

        foreach ($targets as $targetCardId) {
            $state->moveInPlayToDiscard($targetCardId);
        }
    }
}
