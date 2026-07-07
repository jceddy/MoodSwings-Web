<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/**
 * Exhilaration: "To play this card, put one of your moods into the
 * discard pile. If you can't do that, you can't play this card. While
 * in play, score your moods an extra time." Unconditional (no "may") --
 * doubles its owner's whole round total, resolved directly by
 * RoundScorer::score() checking for effect_key 'exhilaration' rather
 * than through any method here, since it's not a value computation for
 * Exhilaration's own card so much as a scoring-time rule.
 */
final class ExhilarationEffect extends AbstractMoodEffect
{
    public function canPayToPlayCost(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): bool
    {
        return $state->moodsOwnedBy($playerId) !== [];
    }

    public function payToPlayCost(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $targetCardId = $choices->requireInt('discard_mood_id');
        if (!$state->isInPlay($targetCardId) || $state->ownerOf($targetCardId) !== $playerId) {
            throw new InvalidChoiceException("Card {$targetCardId} is not one of player {$playerId}'s moods in play");
        }

        $state->moveInPlayToDiscard($targetCardId);
    }
}
