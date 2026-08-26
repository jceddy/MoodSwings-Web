<?php

declare(strict_types=1);

namespace MoodSwings\Rules\ChaosEffects;

use MoodSwings\Rules\AbstractChaosMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\PlayerChoices;

/**
 * chaos_042 (uncommon, after_playing): "Reveal the top card of your deck.
 * If it matches the color of one of your moods, put it into your hand."
 * A non-matching top card is left exactly where it already is -- only
 * recorded as revealed, never moved -- since drawCard() is the only way a
 * deck's own top card leaves it, and it's only called when the match
 * condition holds.
 */
final class Chaos042Effect extends AbstractChaosMoodEffect
{
    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $deck = $state->deck($playerId);
        if ($deck === []) {
            return;
        }

        $topCardId = $deck[0];
        $state->recordRevealedCard($topCardId);
        $topColor = $state->colorOf($topCardId);

        foreach ($state->moodsOwnedBy($playerId) as $mood) {
            if ($state->colorOf($mood->cardId) === $topColor) {
                $state->drawCard($playerId);

                return;
            }
        }
    }
}
