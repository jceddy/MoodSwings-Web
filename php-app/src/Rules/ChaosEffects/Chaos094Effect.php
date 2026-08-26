<?php

declare(strict_types=1);

namespace MoodSwings\Rules\ChaosEffects;

use MoodSwings\Rules\AbstractChaosMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/** chaos_094 (uncommon, after_playing): "You may put one of your black or green moods into the discard pile. If you do, put up to two moods each with a value of 3 or less into the discard pile." */
final class Chaos094Effect extends AbstractChaosMoodEffect
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
        if (!in_array($state->colorOf($discardMoodId), ['black', 'green'], true)) {
            throw new InvalidChoiceException("Card {$discardMoodId} is not black or green");
        }
        $state->moveInPlayToDiscard($discardMoodId);

        $targetCardIds = array_unique($choices->ints('other_mood_card_ids'));
        if (count($targetCardIds) > 2) {
            throw new InvalidChoiceException('Choose at most two other moods');
        }
        foreach ($targetCardIds as $targetCardId) {
            if (!$state->isInPlay($targetCardId) || $state->valueOf($targetCardId) > 3) {
                throw new InvalidChoiceException("Card {$targetCardId} is not a valid target");
            }
        }
        foreach ($targetCardIds as $targetCardId) {
            $state->moveInPlayToDiscard($targetCardId);
        }
    }
}
