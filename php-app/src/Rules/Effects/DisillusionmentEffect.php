<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\PlayerChoices;

/**
 * Disillusionment: "After playing this mood, starting with the next
 * player in turn order, each player may choose a color. Put each other
 * mood that shares one of those colors into the discard pile." Each
 * player's color choice is public-information-scoped (one of the five
 * printed colors) with no interactive API to collect a choice from every
 * other player mid-play, so -- same rationale as Instability -- one
 * random color is picked per player at the table (turn order doesn't
 * change the outcome, since every pick is simultaneous-in-effect).
 * "Each other mood" excludes only Disillusionment itself, regardless of
 * owner.
 */
final class DisillusionmentEffect extends AbstractMoodEffect
{
    private const COLORS = ['white', 'blue', 'black', 'red', 'green'];

    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $chosenColors = [];
        foreach ($state->playerOrder() as $ignored) {
            $chosenColors[] = self::COLORS[array_rand(self::COLORS)];
        }
        $chosenColors = array_unique($chosenColors);

        foreach ($state->moodsInPlay() as $mood) {
            if ($mood->cardId === $cardId) {
                continue;
            }
            if (in_array($state->colorOf($mood->cardId), $chosenColors, true)) {
                $state->moveInPlayToDiscard($mood->cardId);
            }
        }
    }
}
