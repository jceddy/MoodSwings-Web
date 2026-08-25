<?php

declare(strict_types=1);

namespace MoodSwings\Rules\ChaosEffects;

use MoodSwings\Rules\AbstractChaosMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\ChaosRequiresOpponentDecision;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PendingDecisionRequest;
use MoodSwings\Rules\PlayerChoices;

/**
 * chaos_053 (common, after_playing): "You may discard a card from your
 * hand. If you do, you may play an additional mood this turn." Deferred
 * as a self-targeted `ChaosRequiresOpponentDecision` -- see
 * ChaosDiscardValueToBoostSelfEffect's own docblock for the full "why"
 * (issue #405 follow-up: a hand card chosen up front can go stale if the
 * host card's own printed effect changes the acting player's hand first).
 */
final class Chaos053Effect extends AbstractChaosMoodEffect implements ChaosRequiresOpponentDecision
{
    private const KEY = 'discard_card_id';

    public function pendingDecisionsFor(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): array
    {
        return [
            new PendingDecisionRequest(
                key: self::KEY,
                targetPlayerId: $playerId,
                decisionType: 'chaos_053_discard_card',
                field: [
                    'key' => self::KEY,
                    'type' => 'hand_card',
                    'required' => false,
                    'label' => 'Card to discard for an additional play',
                ],
            ),
        ];
    }

    public function resolveDecisions(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices, array $answers): array
    {
        $discardCardId = ($answers[self::KEY] ?? null)?->int(self::KEY);
        if ($discardCardId === null) {
            return [];
        }
        if (!$state->isInHand($playerId, $discardCardId)) {
            throw new InvalidChoiceException("Card {$discardCardId} is not in your hand");
        }
        $state->moveHandToDiscard($playerId, $discardCardId);
        $state->grantExtraPlay(1, sourceCardId: $cardId);

        return [];
    }
}
