<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects\Custom;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\PlayerChoices;

/**
 * Solace: "After playing this mood -- Until the end of this round, your opponents can't choose your moods." A round-long
 * shield on the player (BoardState::shieldMoodsFromChoices(), kept on the live round and emptied when it ends), not on
 * this mood: it holds even if Solace itself leaves play. Only choosing is stopped (BoardState::isProtectedFromChoiceBy());
 * sweeps and effects where the owner picks their own mood still happen.
 */
final class SolaceEffect extends AbstractMoodEffect
{
    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $state->shieldMoodsFromChoices($playerId);
    }
}
