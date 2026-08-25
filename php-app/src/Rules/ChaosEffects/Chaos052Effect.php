<?php

declare(strict_types=1);

namespace MoodSwings\Rules\ChaosEffects;

use MoodSwings\Rules\AbstractChaosMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/** chaos_052 (uncommon, after_playing): "You may put one of your white or black moods into your hand. If you do, put up to two moods other than this one each with a value of 3 or less into their players' hands." */
final class Chaos052Effect extends AbstractChaosMoodEffect
{
    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $returnCardId = $choices->int('return_mood_card_id');
        if ($returnCardId === null) {
            return;
        }
        if (!in_array($returnCardId, array_map(static fn ($mood) => $mood->cardId, $state->moodsOwnedBy($playerId)), true)) {
            throw new InvalidChoiceException("Card {$returnCardId} is not one of your moods");
        }
        if (!in_array($state->colorOf($returnCardId), ['white', 'black'], true)) {
            throw new InvalidChoiceException("Card {$returnCardId} is not white or black");
        }
        $state->moveInPlayToHand($returnCardId);

        $targetCardIds = array_unique($choices->ints('other_mood_card_ids'));
        if (count($targetCardIds) > 2) {
            throw new InvalidChoiceException('Choose at most two other moods');
        }
        foreach ($targetCardIds as $targetCardId) {
            if ($targetCardId === $cardId || !$state->isInPlay($targetCardId) || $state->valueOf($targetCardId) > 3) {
                throw new InvalidChoiceException("Card {$targetCardId} is not a valid target");
            }
        }
        foreach ($targetCardIds as $targetCardId) {
            $state->moveInPlayToHand($targetCardId);
        }
    }
}
