<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects\Custom;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\PlayerChoices;

/**
 * Whimsy: "After playing this mood -- Reveal a random card from your hand. You may play it as an additional
 * mood this turn." The card is revealed and stays in your hand; the extra play is restricted to that one
 * card (the same 'specific_card_ids' grant Intimidation uses). With an empty hand it does nothing.
 */
final class WhimsyEffect extends AbstractMoodEffect
{
    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $hand = $state->hand($playerId);
        if ($hand === []) {
            return;
        }

        $revealedCardId = $hand[array_rand($hand)];
        $state->recordRevealedCard($revealedCardId);
        $state->grantExtraPlay(1, ['type' => 'specific_card_ids', 'values' => [$revealedCardId]], sourceCardId: $cardId);
    }
}
