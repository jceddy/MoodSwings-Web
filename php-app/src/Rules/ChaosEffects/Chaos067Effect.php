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
 * chaos_067 (rare, after_playing): "You may choose another player. If you
 * do, that player reveals a card from their hand and puts it into your
 * hand. You may play it as an additional mood this turn." Identical
 * printed text to Effects/IntimidationEffect.php -- the other player's own
 * "reveals a card" is that player's real hidden-information choice (see
 * ChaosRequiresOpponentDecision), and the resulting grant is restricted to
 * that specific card, mirroring Intimidation's own 'specific_card_ids'
 * restriction shape -- a maintainer ruling reversing this class's own
 * original design (issue #405 follow-up, reported live for chaos_086's
 * identically-shaped case: "the other player should choose the card from
 * their hand to give to me -- it should not be random").
 */
final class Chaos067Effect extends AbstractChaosMoodEffect implements ChaosRequiresOpponentDecision
{
    private const KEY = 'revealed_card_id';

    public function pendingDecisionsFor(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): array
    {
        if (!$choices->has('target_player_id')) {
            return [];
        }

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
                decisionType: 'chaos_067_reveal_card',
                field: [
                    'key' => self::KEY,
                    'type' => 'hand_card',
                    'required' => true,
                    'label' => 'Choose a card from your hand to reveal',
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
        $revealedCardId = $answers[self::KEY]->requireInt(self::KEY);

        if (!$state->isInHand($targetPlayerId, $revealedCardId)) {
            throw new InvalidChoiceException("Card {$revealedCardId} is not in player {$targetPlayerId}'s hand");
        }

        $state->giveHandCardToPlayer($targetPlayerId, $playerId, $revealedCardId);
        $state->grantExtraPlay(1, ['type' => 'specific_card_ids', 'values' => [$revealedCardId]], sourceCardId: $cardId);

        return [];
    }
}
