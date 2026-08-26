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
 * chaos_086 (common, after_playing): "Choose another player. That player
 * chooses a card from their hand and gives it to you." Identical printed
 * text to Effects/CompulsionEffect.php -- the target's own choice of which
 * hand card to give up is real hidden information, genuinely decided by
 * that player (see ChaosRequiresOpponentDecision) -- a maintainer ruling
 * reversing this class's own original design (issue #405 follow-up,
 * reported live: "the other player should choose the card from their hand
 * to give to me -- it should not be random").
 */
final class Chaos086Effect extends AbstractChaosMoodEffect implements ChaosRequiresOpponentDecision
{
    private const KEY = 'given_card_id';

    public function pendingDecisionsFor(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): array
    {
        $targetPlayerId = $choices->requireInt('target_player_id');
        if (!in_array($targetPlayerId, $state->activePlayerOrder(), true) || $targetPlayerId === $playerId) {
            throw new InvalidChoiceException("Player {$targetPlayerId} is not a valid player");
        }

        if ($state->hand($targetPlayerId) === []) {
            return [];
        }

        return [
            new PendingDecisionRequest(
                key: self::KEY,
                targetPlayerId: $targetPlayerId,
                decisionType: 'chaos_086_give_card',
                field: [
                    'key' => self::KEY,
                    'type' => 'hand_card',
                    'required' => true,
                    'label' => 'Choose a card from your hand to give up',
                ],
            ),
        ];
    }

    public function resolveDecisions(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices, array $answers): array
    {
        if (!isset($answers[self::KEY])) {
            return [];
        }

        $targetPlayerId = $choices->requireInt('target_player_id');
        $givenCardId = $answers[self::KEY]->requireInt(self::KEY);

        if (!$state->isInHand($targetPlayerId, $givenCardId)) {
            throw new InvalidChoiceException("Card {$givenCardId} is not in player {$targetPlayerId}'s hand");
        }

        $state->giveHandCardToPlayer($targetPlayerId, $playerId, $givenCardId);

        return [];
    }
}
