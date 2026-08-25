<?php

declare(strict_types=1);

namespace MoodSwings\Rules\ChaosEffects;

use MoodSwings\Rules\AbstractChaosMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/** chaos_103 (mythic, after_playing): "You may put any number of your other moods into your hand. If you do, you may play that many additional moods this turn." */
final class Chaos103Effect extends AbstractChaosMoodEffect
{
    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $targetCardIds = array_unique($choices->ints('return_mood_card_ids'));
        if ($targetCardIds === []) {
            return;
        }
        $ownCardIds = array_map(static fn ($mood) => $mood->cardId, $state->moodsOwnedBy($playerId));
        foreach ($targetCardIds as $targetCardId) {
            if ($targetCardId === $cardId || !in_array($targetCardId, $ownCardIds, true)) {
                throw new InvalidChoiceException("Card {$targetCardId} is not one of your other moods");
            }
        }

        foreach ($targetCardIds as $targetCardId) {
            $state->moveInPlayToHand($targetCardId);
        }
        $state->grantExtraPlay(count($targetCardIds), sourceCardId: $cardId);
    }
}
