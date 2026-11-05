<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects\Custom;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PendingDecisionRequest;
use MoodSwings\Rules\PlayerChoices;
use MoodSwings\Rules\TurnStartEffect;

/**
 * Maturity: "At the start of each of your turns, this mood's value permanently increases by 2." Uses the
 * same permanent per-mood value delta the Chaos Draft effects do (BoardState::adjustChaosValueDelta()).
 */
final class MaturityEffect extends AbstractMoodEffect implements TurnStartEffect
{
    private const INCREASE = 2;

    public function turnStartRequests(BoardState $state, int $cardId, int $playerId): ?array
    {
        return null;
    }

    public function applyTurnStart(BoardState $state, int $cardId, int $playerId, array $answers): array
    {
        $state->adjustChaosValueDelta($cardId, self::INCREASE);

        return [];
    }
}
