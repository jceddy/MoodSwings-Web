<?php

declare(strict_types=1);

namespace MoodSwings\Rules\ChaosEffects;

use MoodSwings\Rules\AbstractChaosMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/**
 * chaos_038 (common, after_playing): "You may put another one of your
 * moods into your hand. You may play an additional mood this turn." Two
 * independent clauses -- the extra play is granted regardless of whether
 * the optional return-to-hand happens.
 */
final class Chaos038Effect extends AbstractChaosMoodEffect
{
    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $returnCardId = $choices->int('return_mood_card_id');
        if ($returnCardId !== null) {
            if ($returnCardId === $cardId || !in_array($returnCardId, array_map(
                static fn ($mood) => $mood->cardId,
                $state->moodsOwnedBy($playerId),
            ), true)) {
                throw new InvalidChoiceException("Card {$returnCardId} is not one of your other moods");
            }
            $state->moveInPlayToHand($returnCardId);
        }

        $state->grantExtraPlay(1, sourceCardId: $cardId);
    }
}
