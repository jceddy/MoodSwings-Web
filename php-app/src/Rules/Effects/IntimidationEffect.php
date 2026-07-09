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
 * Intimidation: "After playing this mood, you may choose another player.
 * If you do, that player reveals a card from their hand and puts it into
 * your hand. You may play it as an additional mood this turn." The
 * revealed card is the target's own real choice -- see
 * RequiresOpponentDecision. The resulting grant is restricted to that
 * one specific card via the 'specific_card_ids' restriction type (see
 * BoardState::grantAllows()), not just any card sharing some trait.
 */
final class IntimidationEffect extends AbstractMoodEffect implements RequiresOpponentDecision
{
    private const KEY = 'revealed_card_id';

    public function pendingDecisionsFor(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): array
    {
        if (!$choices->has('target_player_id')) {
            return [];
        }

        $targetPlayerId = $choices->requireInt('target_player_id');
        if (!in_array($targetPlayerId, $state->playerOrder(), true)) {
            throw new InvalidChoiceException("Player {$targetPlayerId} is not a valid player");
        }
        if ($targetPlayerId === $playerId) {
            throw new InvalidChoiceException('Intimidation must target another player');
        }

        if ($state->hand($targetPlayerId) === []) {
            return [];
        }

        return [
            new PendingDecisionRequest(
                key: self::KEY,
                targetPlayerId: $targetPlayerId,
                decisionType: 'intimidation_reveal_card',
                field: [
                    'key' => self::KEY,
                    'type' => 'hand_card',
                    'required' => true,
                    'label' => 'Choose a card from your hand to reveal',
                ],
            ),
        ];
    }

    public function resolveDecisions(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices, array $answers): void
    {
        if (!isset($answers[self::KEY])) {
            return;
        }

        $targetPlayerId = $choices->requireInt('target_player_id');
        $revealedCardId = $answers[self::KEY]->requireInt(self::KEY);

        if (!$state->isInHand($targetPlayerId, $revealedCardId)) {
            throw new InvalidChoiceException("Card {$revealedCardId} is not in player {$targetPlayerId}'s hand");
        }

        $state->giveHandCardToPlayer($targetPlayerId, $playerId, $revealedCardId);
        $state->grantExtraPlay(1, ['type' => 'specific_card_ids', 'values' => [$revealedCardId]], sourceCardId: $cardId);
    }
}
