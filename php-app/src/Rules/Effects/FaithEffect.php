<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/**
 * Faith: "After playing this mood, you may discard a green or blue card
 * from your hand. If you do, suppress any mood. It remains suppressed for
 * as long as you have this mood." The suppression is tied to Faith itself
 * as its source (BoardState::suppress()'s $sourceCardId, expiring
 * 'while_source_in_play'), so it lifts automatically once Faith leaves
 * play -- see BoardState::clearSuppressionsFrom().
 */
final class FaithEffect extends AbstractMoodEffect
{
    private const QUALIFYING_COLORS = ['green', 'blue'];

    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $discardCardId = $choices->int('discard_card_id');
        if ($discardCardId === null) {
            return;
        }
        if (!$state->isInHand($playerId, $discardCardId)) {
            throw new InvalidChoiceException("Card {$discardCardId} is not in player {$playerId}'s hand");
        }
        if (!in_array($state->colorOf($discardCardId), self::QUALIFYING_COLORS, true)) {
            throw new InvalidChoiceException('Discarded card must be green or blue');
        }

        $targetCardId = $choices->requireInt('target_mood_id');
        if (!$state->isInPlay($targetCardId)) {
            throw new InvalidChoiceException("Card {$targetCardId} is not in play");
        }

        $state->moveHandToDiscard($playerId, $discardCardId);
        $state->suppress($targetCardId, 'while_source_in_play', $cardId);
    }
}
