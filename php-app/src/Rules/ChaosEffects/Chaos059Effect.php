<?php

declare(strict_types=1);

namespace MoodSwings\Rules\ChaosEffects;

use MoodSwings\Rules\AbstractChaosMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/** chaos_059 (uncommon, after_playing): "You may choose one: Put a green or white mood into the discard pile. -- Put all green and white moods into the discard pile." */
final class Chaos059Effect extends AbstractChaosMoodEffect
{
    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $mode = $choices->string('mode');
        if ($mode === null) {
            return;
        }

        if ($mode === 'all') {
            foreach ($state->moodsInPlay() as $mood) {
                if (in_array($state->colorOf($mood->cardId), ['green', 'white'], true)) {
                    $state->moveInPlayToDiscard($mood->cardId);
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
        if (!in_array($state->colorOf($targetCardId), ['green', 'white'], true)) {
            throw new InvalidChoiceException("Card {$targetCardId} is not green or white");
        }

        $state->moveInPlayToDiscard($targetCardId);
    }
}
