<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;

/** Happiness: "While in play, this mood's value is 8 if a player has both a red mood and a white mood." */
final class HappinessEffect extends AbstractMoodEffect
{
    public function computeValue(BoardState $state, int $cardId): int
    {
        $row = $state->catalogRow($state->effectiveCardId($cardId));

        foreach ($state->playerOrder() as $playerId) {
            $hasRed = false;
            $hasWhite = false;
            foreach ($state->moodsOwnedBy($playerId) as $mood) {
                $color = $state->colorOf($mood->cardId);
                $hasRed = $hasRed || $color === 'red';
                $hasWhite = $hasWhite || $color === 'white';
            }
            if ($hasRed && $hasWhite) {
                return $row['altValue'];
            }
        }

        return $row['baseValue'];
    }
}
