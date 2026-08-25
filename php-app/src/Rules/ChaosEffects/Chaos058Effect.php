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
 * chaos_058 (common, after_playing): "You may give a card from your hand
 * to another player. If you do, this mood's value becomes 6." Reported
 * live (issue #405 follow-up): attaching this to Rationalization and
 * choosing its own "rotate hands" mode threw "Card is not in your hand"
 * instead of letting the player choose a card to give away -- the whole
 * hand had already been rotated away by Rationalization's own
 * afterPlaying() before this class's own (up-front-submitted)
 * `hand_card_id` was validated against it. `recipient_player_id` stays a
 * synchronous, up-front choice (unaffected -- who to give a card to
 * doesn't depend on hand contents), acting as the "would you like to?"
 * gate the same way Intimidation's/Arrogance's own up-front target does;
 * `hand_card_id` itself is deferred as a self-targeted
 * `ChaosRequiresOpponentDecision` -- see ChaosDiscardValueToBoostSelfEffect's
 * own docblock for the full "why" -- so its own candidates are drawn from
 * the acting player's hand as it stands AFTER the host card's own
 * afterPlaying() has fully resolved.
 */
final class Chaos058Effect extends AbstractChaosMoodEffect implements ChaosRequiresOpponentDecision
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
                decisionType: 'chaos_058_give_card',
                field: [
                    'key' => self::KEY,
                    'type' => 'hand_card',
                    'required' => false,
                    'label' => 'Card to give away',
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

        $recipientId = $choices->requireInt('recipient_player_id');
        $state->giveHandCardToPlayer($playerId, $recipientId, $handCardId);
        $state->setValueOverride($cardId, 6);

        return [];
    }
}
