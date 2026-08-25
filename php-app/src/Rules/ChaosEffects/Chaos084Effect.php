<?php

declare(strict_types=1);

namespace MoodSwings\Rules\ChaosEffects;

use MoodSwings\Rules\AbstractChaosMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/** chaos_084 (common, after_playing): "You may put one of your other moods into the discard pile (moods are cards in play). If you do, you may play an additional mood this turn." */
final class Chaos084Effect extends AbstractChaosMoodEffect
{
    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $discardMoodId = $choices->int('discard_mood_card_id');
        if ($discardMoodId === null) {
            return;
        }
        if ($discardMoodId === $cardId || !in_array($discardMoodId, array_map(static fn ($mood) => $mood->cardId, $state->moodsOwnedBy($playerId)), true)) {
            throw new InvalidChoiceException("Card {$discardMoodId} is not one of your other moods");
        }
        $state->moveInPlayToDiscard($discardMoodId);
        $state->grantExtraPlay(1, sourceCardId: $cardId);
    }
}
