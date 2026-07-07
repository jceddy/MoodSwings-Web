<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\PlayerChoices;

/**
 * Rage: "After playing this mood, you may put all other moods with a
 * value of 3 or less into the discard pile." Like Wrath, a yes/no "you
 * may" with a fixed (if conditional) target set, rather than player-chosen
 * ids -- contrast with Anger/Courage, where the player picks which
 * qualifying moods to affect.
 */
final class RageEffect extends AbstractMoodEffect
{
    private const MAXIMUM_VALUE = 3;

    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        if (!$choices->bool('discard_qualifying_moods')) {
            return;
        }

        $targets = [];
        foreach ($state->moodsInPlay() as $mood) {
            if ($mood->cardId !== $cardId && $state->valueOf($mood->cardId) <= self::MAXIMUM_VALUE) {
                $targets[] = $mood->cardId;
            }
        }

        foreach ($targets as $targetCardId) {
            $state->moveInPlayToDiscard($targetCardId);
        }
    }
}
