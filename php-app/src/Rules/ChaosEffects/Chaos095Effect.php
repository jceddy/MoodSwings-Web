<?php

declare(strict_types=1);

namespace MoodSwings\Rules\ChaosEffects;

use MoodSwings\Rules\AbstractChaosMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/** chaos_095 (rare, after_playing): "You may put two of your other moods into the discard pile (moods are cards in play). If you do, this mood's value becomes 9." */
final class Chaos095Effect extends AbstractChaosMoodEffect
{
    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $targetCardIds = array_unique($choices->ints('discard_mood_card_ids'));
        if ($targetCardIds === []) {
            return;
        }
        if (count($targetCardIds) !== 2) {
            throw new InvalidChoiceException('Choose exactly two of your other moods');
        }
        $ownCardIds = array_map(static fn ($mood) => $mood->cardId, $state->moodsOwnedBy($playerId));
        foreach ($targetCardIds as $targetCardId) {
            if ($targetCardId === $cardId || !in_array($targetCardId, $ownCardIds, true)) {
                throw new InvalidChoiceException("Card {$targetCardId} is not one of your other moods");
            }
        }

        foreach ($targetCardIds as $targetCardId) {
            $state->moveInPlayToDiscard($targetCardId);
        }
        $state->setValueOverride($cardId, 9);
    }
}
