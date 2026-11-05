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
 * Rumination: "At the start of each of your turns, you may put one of your moods into your hand."
 * Rumination itself is one of the legal choices.
 */
final class RuminationEffect extends AbstractMoodEffect implements TurnStartEffect
{
    private const KEY = 'target_mood_id';

    public function turnStartRequests(BoardState $state, int $cardId, int $playerId): ?array
    {
        return [TurnStartSupport::ownMoodRequest(
            $state, self::KEY, $playerId, false,
            'Rumination: put one of your moods into your hand',
        )];
    }

    public function applyTurnStart(BoardState $state, int $cardId, int $playerId, array $answers): array
    {
        $targetCardId = ($answers[self::KEY] ?? null)?->int(self::KEY);
        if ($targetCardId === null) {
            return [];
        }
        if (!$state->isInPlay($targetCardId) || $state->ownerOf($targetCardId) !== $playerId) {
            throw new InvalidChoiceException("Card {$targetCardId} is not one of player {$playerId}'s moods");
        }
        $state->moveInPlayToHand($targetCardId);

        return [];
    }
}
