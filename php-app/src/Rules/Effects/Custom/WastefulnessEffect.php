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
 * Wastefulness: "At the start of each of your turns, you may put the top card of the deck into the
 * discard pile." In a game with separate decks (a duel) that's the owner's own deck.
 */
final class WastefulnessEffect extends AbstractMoodEffect implements TurnStartEffect
{
    private const KEY = 'discard_top_card';

    public function turnStartRequests(BoardState $state, int $cardId, int $playerId): ?array
    {
        if ($state->deck($playerId) === []) {
            return [];
        }

        return [new PendingDecisionRequest(
            key: self::KEY,
            targetPlayerId: $playerId,
            decisionType: 'turn_start_trigger',
            field: ['key' => self::KEY, 'type' => 'bool', 'required' => false, 'label' => 'Wastefulness: put the top card of the deck into the discard pile'],
        )];
    }

    public function applyTurnStart(BoardState $state, int $cardId, int $playerId, array $answers): array
    {
        if (isset($answers[self::KEY]) && $answers[self::KEY]->bool(self::KEY)) {
            $state->moveDeckTopToDiscard($playerId);
        }

        return [];
    }
}
