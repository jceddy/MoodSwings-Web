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
 * chaos_025 (rare, after_playing): "You may discard a card from your
 * hand. If you do, suppress all other moods that share a color with the
 * discarded card. Those moods remain suppressed for as long as you have
 * this mood." Deferred as a self-targeted `ChaosRequiresOpponentDecision`
 * -- see ChaosDiscardValueToBoostSelfEffect's own docblock for the full
 * "why" (issue #405 follow-up: a hand card chosen up front can go stale
 * if the host card's own printed effect changes the acting player's hand
 * first).
 */
final class Chaos025Effect extends AbstractChaosMoodEffect implements ChaosRequiresOpponentDecision
{
    private const KEY = 'discard_card_id';

    public function pendingDecisionsFor(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): array
    {
        return [
            new PendingDecisionRequest(
                key: self::KEY,
                targetPlayerId: $playerId,
                decisionType: 'chaos_025_discard_card',
                field: [
                    'key' => self::KEY,
                    'type' => 'hand_card',
                    'required' => false,
                    'label' => "Card to discard (its color determines what gets suppressed)",
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

        $color = $state->colorOf($discardCardId);
        $state->moveHandToDiscard($playerId, $discardCardId);

        foreach ($state->moodsInPlay() as $mood) {
            if ($mood->cardId !== $cardId && $state->colorOf($mood->cardId) === $color) {
                $state->suppress($mood->cardId, 'while_source_in_play', $cardId);
            }
        }

        return [];
    }
}
