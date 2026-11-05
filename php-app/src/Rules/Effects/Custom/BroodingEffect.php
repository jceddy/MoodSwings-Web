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
 * Brooding: "At the start of each of your turns, you may put a card from the discard pile into your
 * hand. If you do, discard a card from your hand." The discard is a second, follow-up decision so the
 * card just taken is among the choices (taking it and discarding it again is legal).
 */
final class BroodingEffect extends AbstractMoodEffect implements TurnStartEffect
{
    private const TAKE_KEY = 'take_card_id';
    private const DISCARD_KEY = 'discard_hand_card_id';

    public function turnStartRequests(BoardState $state, int $cardId, int $playerId): ?array
    {
        if ($state->discardPile() === []) {
            return [];
        }

        return [new PendingDecisionRequest(
            key: self::TAKE_KEY,
            targetPlayerId: $playerId,
            decisionType: 'turn_start_trigger',
            field: ['key' => self::TAKE_KEY, 'type' => 'discard_card', 'required' => false, 'label' => 'Brooding: put a card from the discard pile into your hand (then discard a card from your hand)'],
        )];
    }

    public function applyTurnStart(BoardState $state, int $cardId, int $playerId, array $answers): array
    {
        if (isset($answers[self::DISCARD_KEY])) {
            $discardCardId = $answers[self::DISCARD_KEY]->requireInt(self::DISCARD_KEY);
            if (!$state->isInHand($playerId, $discardCardId)) {
                throw new InvalidChoiceException("Card {$discardCardId} is not in player {$playerId}'s hand");
            }
            $state->moveHandToDiscard($playerId, $discardCardId);

            return [];
        }

        $takeCardId = ($answers[self::TAKE_KEY] ?? null)?->int(self::TAKE_KEY);
        if ($takeCardId === null) {
            return [];
        }
        if (!in_array($takeCardId, $state->discardPile(), true)) {
            throw new InvalidChoiceException("Card {$takeCardId} is not in the discard pile");
        }
        $state->moveDiscardToHand($playerId, $takeCardId);

        return [new PendingDecisionRequest(
            key: self::DISCARD_KEY,
            targetPlayerId: $playerId,
            decisionType: 'turn_start_trigger',
            field: ['key' => self::DISCARD_KEY, 'type' => 'hand_card', 'required' => true, 'label' => 'Brooding: discard a card from your hand'],
        )];
    }
}
