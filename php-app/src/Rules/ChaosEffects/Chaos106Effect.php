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
 * chaos_106 (common, after_playing): "You may put a card from your hand
 * on the bottom of the deck. If you do, draw a card." Deferred as a
 * self-targeted `ChaosRequiresOpponentDecision` -- see
 * ChaosDiscardValueToBoostSelfEffect's own docblock for the full "why"
 * (issue #405 follow-up: a hand card chosen up front can go stale if the
 * host card's own printed effect changes the acting player's hand first).
 */
final class Chaos106Effect extends AbstractChaosMoodEffect implements ChaosRequiresOpponentDecision
{
    private const KEY = 'hand_card_id';

    public function pendingDecisionsFor(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): array
    {
        return [
            new PendingDecisionRequest(
                key: self::KEY,
                targetPlayerId: $playerId,
                decisionType: 'chaos_106_bottom_deck_card',
                field: [
                    'key' => self::KEY,
                    'type' => 'hand_card',
                    'required' => false,
                    'label' => 'Hand card to bottom-deck (then draw)',
                ],
            ),
        ];
    }

    public function resolveDecisions(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices, array $answers): array
    {
        $handCardId = ($answers[self::KEY] ?? null)?->int(self::KEY);
        if ($handCardId === null) {
            return [];
        }
        if (!$state->isInHand($playerId, $handCardId)) {
            throw new InvalidChoiceException("Card {$handCardId} is not in your hand");
        }
        $state->moveHandToBottomOfDeck($playerId, $handCardId);
        $state->drawCard($playerId);

        return [];
    }
}
