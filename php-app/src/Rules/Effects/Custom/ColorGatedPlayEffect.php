<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects\Custom;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\PlayerChoices;

/**
 * The colorless gate commons -- Reverence (white), Detachment (blue), Resentment (black), Impatience (red),
 * Contentment (green): "You can only play this card if you have a <color> mood." Only YOUR moods count (the
 * group's ruling Q7), judged by each mood's current color, and a suppressed mood still counts. There is
 * nothing to pay -- the gate is purely a play restriction, checked wherever a card's playability is.
 */
final class ColorGatedPlayEffect extends AbstractMoodEffect
{
    public function __construct(private readonly string $requiredColor)
    {
    }

    public function canPayToPlayCost(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): bool
    {
        foreach ($state->moodsOwnedBy($playerId) as $mood) {
            if ($state->colorOf($mood->cardId) === $this->requiredColor) {
                return true;
            }
        }

        return false;
    }

    public function payToPlayCost(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
    }
}
