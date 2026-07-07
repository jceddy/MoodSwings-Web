<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/**
 * Imagination: "After playing this mood, choose a color. While in play,
 * all moods are the chosen color and no other colors." The "while in
 * play" half of this is handled globally by BoardState::colorOf() (every
 * color-counting effect already asks the board for a mood's color rather
 * than reading the catalog directly, so this doesn't need its own
 * computeValue) -- this class only needs to remember which color was
 * chosen.
 */
final class ImaginationEffect extends AbstractMoodEffect
{
    private const VALID_COLORS = ['white', 'blue', 'black', 'red', 'green'];

    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $color = $choices->requireString('color');
        if (!in_array($color, self::VALID_COLORS, true)) {
            throw new InvalidChoiceException("'{$color}' is not a valid color");
        }

        $state->setEffectState($cardId, 'color', $color);
    }
}
