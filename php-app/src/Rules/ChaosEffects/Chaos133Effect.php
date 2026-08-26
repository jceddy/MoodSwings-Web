<?php

declare(strict_types=1);

namespace MoodSwings\Rules\ChaosEffects;

use MoodSwings\Rules\AbstractChaosMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/**
 * chaos_133 (mythic, after_playing): "Choose a color, then permanently
 * increase the value of this mood by 2 for each mood of the chosen color
 * and each card in the discard pile of the chosen color." Unlike
 * Effects/WonderEffect.php's identically-worded 'while_in_play' ability
 * (a continuously recomputed bonus), this effect's shape is
 * 'after_playing' -- a one-time snapshot taken right now, fixed via
 * adjustChaosValueDelta() rather than recomputed on every future
 * valueOf() call. A DELTA ("increase ... BY 2 for each..."), not an
 * absolute override -- adjustChaosValueDelta() stacks this with
 * whatever this card's value already is (its own dice/alt value
 * included) instead of replacing it; see that method's own docblock on
 * BoardState.
 */
final class Chaos133Effect extends AbstractChaosMoodEffect
{
    private const VALID_COLORS = ['white', 'blue', 'black', 'red', 'green'];
    private const VALUE_PER_MATCH = 2;

    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $color = $choices->requireString('color');
        if (!in_array($color, self::VALID_COLORS, true)) {
            throw new InvalidChoiceException("'{$color}' is not a valid color");
        }

        $count = 0;
        foreach ($state->moodsInPlay() as $mood) {
            if ($state->colorOf($mood->cardId) === $color) {
                $count++;
            }
        }
        foreach ($state->discardPile() as $discardedCardId) {
            if ($state->colorOf($discardedCardId) === $color) {
                $count++;
            }
        }

        $state->adjustChaosValueDelta($cardId, self::VALUE_PER_MATCH * $count);
    }
}
