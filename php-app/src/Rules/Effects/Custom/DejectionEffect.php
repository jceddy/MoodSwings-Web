<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects\Custom;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PendingDecisionRequest;
use MoodSwings\Rules\PlayerChoices;
use MoodSwings\Rules\RequiresOpponentDecision;

/**
 * Dejection: "After playing this mood -- Each player discards a card from their hand." Suspicion forced onto
 * every player, the acting player included: each player with a card in hand answers their own decision, and
 * nothing is discarded until every answer is in (the "each player" is simultaneous). An empty-handed player
 * discards nothing and isn't asked.
 */
final class DejectionEffect extends AbstractMoodEffect implements RequiresOpponentDecision
{
    private const KEY_PREFIX = 'discarded_card_id_';

    public function pendingDecisionsFor(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): array
    {
        $requests = [];
        foreach ($state->activePlayerOrder() as $ownerId) {
            if ($state->hand($ownerId) === []) {
                continue;
            }

            $requests[] = new PendingDecisionRequest(
                key: self::KEY_PREFIX . $ownerId,
                targetPlayerId: $ownerId,
                decisionType: 'dejection_discard_card',
                field: [
                    'key' => self::KEY_PREFIX . $ownerId,
                    'type' => 'hand_card',
                    'required' => true,
                    'label' => 'Dejection: choose a card from your hand to discard',
                ],
            );
        }

        return $requests;
    }

    public function resolveDecisions(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices, array $answers): array
    {
        $discards = [];
        foreach ($state->activePlayerOrder() as $ownerId) {
            $key = self::KEY_PREFIX . $ownerId;
            if (!isset($answers[$key])) {
                continue;
            }

            $discardedCardId = $answers[$key]->requireInt($key);
            if (!$state->isInHand($ownerId, $discardedCardId)) {
                throw new InvalidChoiceException("Card {$discardedCardId} is not in player {$ownerId}'s hand");
            }
            $discards[$ownerId] = $discardedCardId;
        }

        foreach ($discards as $ownerId => $discardedCardId) {
            $state->moveHandToDiscard($ownerId, $discardedCardId);
        }

        return [];
    }
}
