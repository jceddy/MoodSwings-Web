<?php

declare(strict_types=1);

namespace MoodSwings\Rules\ChaosEffects;

use MoodSwings\Rules\AbstractChaosMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/** chaos_054 (uncommon, after_playing): "You may put one of your blue or red moods into the discard pile. If you do, you may play an additional mood this turn from the discard pile." */
final class Chaos054Effect extends AbstractChaosMoodEffect
{
    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $discardMoodId = $choices->int('discard_mood_card_id');
        if ($discardMoodId === null) {
            return;
        }
        if (!in_array($discardMoodId, array_map(static fn ($mood) => $mood->cardId, $state->moodsOwnedBy($playerId)), true)) {
            throw new InvalidChoiceException("Card {$discardMoodId} is not one of your moods");
        }
        if (!in_array($state->colorOf($discardMoodId), ['blue', 'red'], true)) {
            throw new InvalidChoiceException("Card {$discardMoodId} is not blue or red");
        }
        $state->moveInPlayToDiscard($discardMoodId);
        $state->grantExtraPlay(1, ['source' => 'discard'], sourceCardId: $cardId);
    }
}
