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
 * Volatility: "At the start of each of your turns, a random mood with a value of 1 or less is put into
 * the discard pile." Any owner's mood (Volatility's own included) can be the one picked.
 */
final class VolatilityEffect extends AbstractMoodEffect implements TurnStartEffect
{
    private const MAXIMUM_VALUE = 1;

    public function turnStartRequests(BoardState $state, int $cardId, int $playerId): ?array
    {
        return null;
    }

    public function applyTurnStart(BoardState $state, int $cardId, int $playerId, array $answers): array
    {
        $candidates = [];
        foreach ($state->moodsInPlay() as $mood) {
            if ($state->valueOf($mood->cardId) <= self::MAXIMUM_VALUE) {
                $candidates[] = $mood->cardId;
            }
        }
        if ($candidates !== []) {
            $state->moveInPlayToDiscard($candidates[array_rand($candidates)]);
        }

        return [];
    }
}
