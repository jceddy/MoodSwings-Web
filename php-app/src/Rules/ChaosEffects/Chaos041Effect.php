<?php

declare(strict_types=1);

namespace MoodSwings\Rules\ChaosEffects;

use MoodSwings\Rules\AbstractChaosMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/** chaos_041 (common, after_playing): "You may choose one: Put a red or green mood into its player's hand. -- Put all red and green moods into their players' hands." */
final class Chaos041Effect extends AbstractChaosMoodEffect
{
    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $mode = $choices->string('mode');
        if ($mode === null) {
            return;
        }

        if ($mode === 'all') {
            foreach ($state->moodsInPlay() as $mood) {
                if (in_array($state->colorOf($mood->cardId), ['red', 'green'], true)) {
                    $state->moveInPlayToHand($mood->cardId);
                }
            }

            return;
        }

        if ($mode !== 'single') {
            throw new InvalidChoiceException("'{$mode}' is not a valid mode");
        }

        $targetCardId = $choices->requireInt('mood_card_id');
        if (!$state->isInPlay($targetCardId)) {
            throw new InvalidChoiceException("Card {$targetCardId} is not in play");
        }
        if (!in_array($state->colorOf($targetCardId), ['red', 'green'], true)) {
            throw new InvalidChoiceException("Card {$targetCardId} is not red or green");
        }

        $state->moveInPlayToHand($targetCardId);
    }
}
