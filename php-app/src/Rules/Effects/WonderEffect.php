<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/**
 * Wonder: "After playing this mood, choose a color. While in play, this
 * mood's value increases by 2 for each mood of the chosen color and each
 * card in the discard pile of the chosen color." Unlike Imagination's
 * color choice, the chosen color here is per-mood state on Wonder itself
 * (see MoodInPlay::$effectState), not a board-wide override.
 *
 * Duplicity can repeat this afterPlaying() a second (or later) time, each
 * repeat with its own fresh color choice -- ruled that Wonder then
 * benefits from EVERY color chosen across all of those invocations, not
 * just the latest one, so the chosen colors accumulate in a list
 * ('colors', not a single overwritten 'color') rather than the last
 * invocation clobbering the earlier choice(s).
 */
final class WonderEffect extends AbstractMoodEffect
{
    private const VALID_COLORS = ['white', 'blue', 'black', 'red', 'green'];
    private const VALUE_PER_MATCH = 2;

    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $color = $choices->requireString('color');
        if (!in_array($color, self::VALID_COLORS, true)) {
            throw new InvalidChoiceException("'{$color}' is not a valid color");
        }

        $colors = $state->effectState($cardId, 'colors') ?? [];
        $colors[] = $color;
        $state->setEffectState($cardId, 'colors', $colors);
    }

    public function computeValue(BoardState $state, int $cardId): int
    {
        $colors = $state->effectState($cardId, 'colors') ?? [];

        $count = 0;
        foreach ($state->moodsInPlay() as $mood) {
            if (in_array($state->colorOf($mood->cardId), $colors, true)) {
                $count++;
            }
        }
        foreach ($state->discardPile() as $discardedCardId) {
            if (in_array($state->colorOf($discardedCardId), $colors, true)) {
                $count++;
            }
        }

        $row = $state->catalogRow($state->effectiveCardId($cardId));

        return $row['baseValue'] + self::VALUE_PER_MATCH * $count;
    }
}
