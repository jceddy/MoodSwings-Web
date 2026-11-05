<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects\Custom;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\PlayerChoices;

/**
 * Honesty: "After playing this mood -- Reveal your hand. If each card in it shares a color with one of your
 * moods, this mood's value becomes 6." The hand is revealed to the table (it shows in the play's log
 * entry). Honesty counts as one of your moods, so its own color (green) qualifies cards; an empty hand
 * qualifies; a colorless card shares no color. The value is locked in once, like Dignity's.
 */
final class HonestyEffect extends AbstractMoodEffect
{
    private const LOCKED_VALUE = 6;

    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $moodColors = [];
        foreach ($state->moodsOwnedBy($playerId) as $mood) {
            if ($state->colorOf($mood->cardId) !== 'colorless') {
                $moodColors[$state->colorOf($mood->cardId)] = true;
            }
        }

        $everyCardShares = true;
        foreach ($state->hand($playerId) as $handCardId) {
            $state->recordRevealedCard($handCardId);
            if (!isset($moodColors[$state->colorOf($handCardId)])) {
                $everyCardShares = false;
            }
        }

        if ($everyCardShares) {
            $state->setValueOverride($cardId, self::LOCKED_VALUE);
        }
    }
}
