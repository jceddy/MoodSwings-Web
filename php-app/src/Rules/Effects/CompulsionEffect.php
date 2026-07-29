<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PendingDecisionRequest;
use MoodSwings\Rules\PlayerChoices;
use MoodSwings\Rules\RequiresOpponentDecision;

/**
 * Compulsion: "After playing this mood, choose another player. That
 * player chooses a card from their hand and gives it to you." Mandatory
 * (no "may"). The target player's own choice of which hand card to give
 * up is real hidden information, genuinely decided by that player -- see
 * RequiresOpponentDecision.
 */
final class CompulsionEffect extends AbstractMoodEffect implements RequiresOpponentDecision
{
    private const KEY = 'given_card_id';

    public function pendingDecisionsFor(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): array
    {
        $targetPlayerId = $choices->requireInt('target_player_id');
        if (!in_array($targetPlayerId, $state->activePlayerOrder(), true)) {
            throw new InvalidChoiceException("Player {$targetPlayerId} is not a valid player");
        }
        if ($targetPlayerId === $playerId) {
            throw new InvalidChoiceException('Compulsion must target another player');
        }

        if ($state->hand($targetPlayerId) === []) {
            return [];
        }

        return [
            new PendingDecisionRequest(
                key: self::KEY,
                targetPlayerId: $targetPlayerId,
                decisionType: 'compulsion_give_card',
                field: [
                    'key' => self::KEY,
                    'type' => 'hand_card',
                    'required' => true,
                    'label' => 'Choose a card from your hand to give up',
                ],
            ),
        ];
    }

    public function resolveDecisions(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices, array $answers): void
    {
        // Missing here means pendingDecisionsFor() returned [] -- the
        // target's hand was already empty, so there was never anything to
        // ask; same no-op as today's original early return.
        if (!isset($answers[self::KEY])) {
            return;
        }

        $targetPlayerId = $choices->requireInt('target_player_id');
        $givenCardId = $answers[self::KEY]->requireInt(self::KEY);

        if (!$state->isInHand($targetPlayerId, $givenCardId)) {
            throw new InvalidChoiceException("Card {$givenCardId} is not in player {$targetPlayerId}'s hand");
        }

        $state->giveHandCardToPlayer($targetPlayerId, $playerId, $givenCardId);
    }
}
