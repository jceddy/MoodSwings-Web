<?php

declare(strict_types=1);

namespace MoodSwings\Rules\ChaosEffects;

use MoodSwings\Rules\AbstractChaosMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/**
 * chaos_014 (uncommon, after_playing): "You may choose one: Suppress a
 * black or red mood. It remains suppressed for as long as you have this
 * mood. -- Suppress all black and red moods. Those moods remain
 * suppressed for as long as you have this mood."
 */
final class Chaos014Effect extends AbstractChaosMoodEffect
{
    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $mode = $choices->string('mode');
        if ($mode === null) {
            return;
        }

        if ($mode === 'all') {
            foreach ($state->moodsInPlay() as $mood) {
                if (in_array($state->colorOf($mood->cardId), ['black', 'red'], true)) {
                    $state->suppress($mood->cardId, 'while_source_in_play', $cardId);
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
        if (!in_array($state->colorOf($targetCardId), ['black', 'red'], true)) {
            throw new InvalidChoiceException("Card {$targetCardId} is not black or red");
        }

        $state->suppress($targetCardId, 'while_source_in_play', $cardId);
    }
}
