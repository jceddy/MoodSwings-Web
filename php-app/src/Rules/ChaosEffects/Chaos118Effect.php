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
 * chaos_118 (uncommon, after_playing): "You may reveal a blue or black
 * card from your hand and give it to another player. If you do, this
 * mood's value becomes 7." `recipient_player_id` stays a synchronous,
 * up-front choice (unaffected -- who to give a card to doesn't depend on
 * hand contents), acting as the "would you like to?" gate the same way
 * Intimidation's/Arrogance's own up-front target does; `hand_card_id`
 * itself is deferred as a self-targeted `ChaosRequiresOpponentDecision`
 * -- see ChaosDiscardValueToBoostSelfEffect's own docblock for the full
 * "why" (issue #405 follow-up, reported live for chaos_058's identically-
 * shaped case: a hand card chosen up front can go stale if the host
 * card's own printed effect changes the acting player's hand first).
 */
final class Chaos118Effect extends AbstractChaosMoodEffect implements ChaosRequiresOpponentDecision
{
    private const KEY = 'hand_card_id';

    public function pendingDecisionsFor(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): array
    {
        if (!$choices->has('recipient_player_id')) {
            return [];
        }

        $recipientId = $choices->requireInt('recipient_player_id');
        if (!in_array($recipientId, $state->activePlayerOrder(), true) || $recipientId === $playerId) {
            throw new InvalidChoiceException("Player {$recipientId} is not a valid recipient");
        }

        return [
            new PendingDecisionRequest(
                key: self::KEY,
                targetPlayerId: $playerId,
                decisionType: 'chaos_118_give_card',
                field: [
                    'key' => self::KEY,
                    'type' => 'hand_card',
                    'required' => false,
                    'filter' => ['colors' => ['blue', 'black']],
                    'label' => 'Blue/black card to reveal and give away',
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
        if (!in_array($state->colorOf($handCardId), ['blue', 'black'], true)) {
            throw new InvalidChoiceException("Card {$handCardId} is not blue or black");
        }

        $recipientId = $choices->requireInt('recipient_player_id');
        $state->recordRevealedCard($handCardId);
        $state->giveHandCardToPlayer($playerId, $recipientId, $handCardId);
        $state->setChaosValueOverride($cardId, 7);

        return [];
    }
}
