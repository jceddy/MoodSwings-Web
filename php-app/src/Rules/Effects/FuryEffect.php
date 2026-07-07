<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\PlayerChoices;

/**
 * Fury: "After playing this mood, each player chooses one of their
 * highest value moods and puts it into the discard pile." Mandatory and
 * affects every player, including whoever played Fury. Among ties for a
 * player's highest value, which specific mood is picked is an arbitrary
 * (but deterministic) simplification -- the rules only distinguish moods
 * by value here, so it doesn't affect the outcome. Every player's target
 * is chosen from a single snapshot of the board before any discards
 * happen, so one player's discard can't change who qualifies as another's
 * highest.
 */
final class FuryEffect extends AbstractMoodEffect
{
    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $targets = [];
        foreach ($state->playerOrder() as $ownerId) {
            $highestCardId = null;
            $highestValue = -1;
            foreach ($state->moodsOwnedBy($ownerId) as $mood) {
                $value = $state->valueOf($mood->cardId);
                if ($value > $highestValue) {
                    $highestValue = $value;
                    $highestCardId = $mood->cardId;
                }
            }
            if ($highestCardId !== null) {
                $targets[] = $highestCardId;
            }
        }

        foreach ($targets as $targetCardId) {
            $state->moveInPlayToDiscard($targetCardId);
        }
    }
}
