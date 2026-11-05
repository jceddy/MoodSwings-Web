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
 * Grievance: "After playing this mood -- Each opponent with more cards in their hand than you puts a card from
 * their hand on the bottom of the deck." Hand sizes are compared as the board stands when the card resolves (the
 * played Grievance has already left your hand); teammates aren't opponents. Each qualifying opponent chooses the
 * card to send back in their own decision, and nothing moves until every answer is in. A hoarding check: it does
 * nothing when you hold the most cards.
 */
final class GrievanceEffect extends AbstractMoodEffect implements RequiresOpponentDecision
{
    private const KEY_PREFIX = 'bottom_card_id_';

    public function pendingDecisionsFor(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): array
    {
        $requests = [];
        foreach ($this->qualifyingOpponentIds($state, $playerId) as $opponentId) {
            $requests[] = new PendingDecisionRequest(
                key: self::KEY_PREFIX . $opponentId,
                targetPlayerId: $opponentId,
                decisionType: 'grievance_bottom_card',
                field: [
                    'key' => self::KEY_PREFIX . $opponentId,
                    'type' => 'hand_card',
                    'required' => true,
                    'label' => 'Grievance: choose a card from your hand to put on the bottom of the deck',
                ],
            );
        }

        return $requests;
    }

    public function resolveDecisions(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices, array $answers): array
    {
        $moves = [];
        foreach ($this->qualifyingOpponentIds($state, $playerId) as $opponentId) {
            $key = self::KEY_PREFIX . $opponentId;
            if (!isset($answers[$key])) {
                continue;
            }

            $handCardId = $answers[$key]->requireInt($key);
            if (!$state->isInHand($opponentId, $handCardId)) {
                throw new InvalidChoiceException("Card {$handCardId} is not in player {$opponentId}'s hand");
            }
            $moves[$opponentId] = $handCardId;
        }

        foreach ($moves as $opponentId => $handCardId) {
            $state->moveHandToBottomOfDeck($opponentId, $handCardId);
        }

        return [];
    }

    /** @return int[] */
    private function qualifyingOpponentIds(BoardState $state, int $playerId): array
    {
        $own = CustomEffectSupport::handCount($state, $playerId);

        return array_values(array_filter(
            CustomEffectSupport::opponentIds($state, $playerId),
            static fn (int $opponentId): bool => CustomEffectSupport::handCount($state, $opponentId) > $own,
        ));
    }
}
