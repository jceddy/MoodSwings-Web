<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects\Custom;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/**
 * Deflection: "After playing this mood -- Put one of your opponents' moods into its player's hand. That player can't play
 * cards with that name during their next turn." The ban is by card name (the printed card -- its effect key is unique per
 * name -- so a duplicate in their hand is barred too), not by the bounced copy, and waits for that player's next turn like
 * Pettiness's: BoardState::nameBanRestriction() comes into force when that turn's grants are computed and lapses with the
 * turn. With no opposing mood the target is simply left out.
 */
final class DeflectionEffect extends AbstractMoodEffect
{
    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $targetCardId = $choices->int('target_mood_id');
        if ($targetCardId === null) {
            return;
        }
        if (!$state->isInPlay($targetCardId) || !in_array($state->ownerOf($targetCardId), CustomEffectSupport::opponentIds($state, $playerId), true)) {
            throw new InvalidChoiceException("Card {$targetCardId} is not one of your opponents' moods in play");
        }

        $ownerId = $state->ownerOf($targetCardId);
        $effectKey = $state->catalogRow($targetCardId)['effectKey'];
        $state->moveInPlayToHand($targetCardId);
        $state->addPendingTurnRestriction($ownerId, BoardState::nameBanRestriction($effectKey));
    }
}
