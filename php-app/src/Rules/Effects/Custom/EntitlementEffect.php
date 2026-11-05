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
 * Entitlement (the restricted printing): "After playing this mood -- Choose an opponent who has one or more
 * cards in their hand and one or more moods. That player may reveal their hand. If they do, choose a card
 * from it and put it into your hand, and you may play it as an additional mood this turn. If they don't,
 * that player chooses one of their highest value moods and puts it into the discard pile." Three possible
 * steps, told apart by which answer key arrives: the target's reveal-or-not choice; then either the
 * player's pick from the revealed hand (with an extra play restricted to that card), or the target's pick
 * among their highest value moods (asked only when several tie). With no qualifying opponent it does nothing.
 */
final class EntitlementEffect extends AbstractMoodEffect implements RequiresOpponentDecision
{
    private const REVEAL_KEY = 'reveal_hand';
    private const PICK_KEY = 'taken_card_id';
    private const SACRIFICE_KEY = 'discard_mood_id';

    public function pendingDecisionsFor(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): array
    {
        if (!$choices->has('target_player_id')) {
            return [];
        }

        $targetPlayerId = $choices->requireInt('target_player_id');
        if (!in_array($targetPlayerId, CustomEffectSupport::opponentIds($state, $playerId), true)) {
            throw new InvalidChoiceException('Entitlement must target one of your opponents');
        }
        if ($state->hand($targetPlayerId) === [] || $state->moodsOwnedBy($targetPlayerId) === []) {
            throw new InvalidChoiceException("Player {$targetPlayerId} needs at least one card in hand and one mood");
        }

        return [new PendingDecisionRequest(
            key: self::REVEAL_KEY,
            targetPlayerId: $targetPlayerId,
            decisionType: 'entitlement_reveal',
            field: [
                'key' => self::REVEAL_KEY, 'type' => 'bool', 'required' => false,
                'label' => 'Entitlement: reveal your hand? (If you do, its owner takes a card from it; if you don\'t, you put one of your highest value moods into the discard pile.)',
            ],
        )];
    }

    public function resolveDecisions(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices, array $answers): array
    {
        $targetPlayerId = $choices->has('target_player_id') ? $choices->requireInt('target_player_id') : null;
        if ($targetPlayerId === null) {
            return [];
        }

        if (isset($answers[self::PICK_KEY])) {
            $pickedCardId = $answers[self::PICK_KEY]->requireInt(self::PICK_KEY);
            if (!$state->isInHand($targetPlayerId, $pickedCardId)) {
                throw new InvalidChoiceException("Card {$pickedCardId} is not in player {$targetPlayerId}'s hand");
            }
            $state->giveHandCardToPlayer($targetPlayerId, $playerId, $pickedCardId);
            $state->grantExtraPlay(1, ['type' => 'specific_card_ids', 'values' => [$pickedCardId]], sourceCardId: $cardId);

            return [];
        }

        if (isset($answers[self::SACRIFICE_KEY])) {
            $moodId = $answers[self::SACRIFICE_KEY]->requireInt(self::SACRIFICE_KEY);
            if (!in_array($moodId, $this->highestMoodIds($state, $targetPlayerId), true)) {
                throw new InvalidChoiceException("Card {$moodId} is not one of player {$targetPlayerId}'s highest value moods");
            }
            $state->moveInPlayToDiscard($moodId);

            return [];
        }

        if (!isset($answers[self::REVEAL_KEY])) {
            return [];
        }

        if ($answers[self::REVEAL_KEY]->bool(self::REVEAL_KEY)) {
            $hand = $state->hand($targetPlayerId);
            if ($hand === []) {
                return [];
            }
            foreach ($hand as $handCardId) {
                $state->recordRevealedCard($handCardId);
            }

            return [CustomEffectSupport::revealedCardRequest(
                self::PICK_KEY, $playerId, $hand,
                'Entitlement: choose a card from the revealed hand to put into your hand (you may play it as an additional mood)',
            )];
        }

        $highest = $this->highestMoodIds($state, $targetPlayerId);
        if (count($highest) === 1) {
            $state->moveInPlayToDiscard($highest[0]);

            return [];
        }
        if ($highest === []) {
            return [];
        }

        return [new PendingDecisionRequest(
            key: self::SACRIFICE_KEY,
            targetPlayerId: $targetPlayerId,
            decisionType: 'entitlement_discard_mood',
            field: [
                'key' => self::SACRIFICE_KEY, 'type' => 'mood', 'scope' => 'own', 'required' => true,
                'label' => 'Entitlement: choose one of your highest value moods to put into the discard pile',
                'candidate_card_ids' => $highest,
            ],
        )];
    }

    /** @return int[] $playerId's moods tied for their highest current value, in play order */
    private function highestMoodIds(BoardState $state, int $playerId): array
    {
        $highestValue = null;
        $ids = [];
        foreach ($state->moodsOwnedBy($playerId) as $mood) {
            $value = $state->valueOf($mood->cardId);
            if ($highestValue === null || $value > $highestValue) {
                $highestValue = $value;
                $ids = [$mood->cardId];
            } elseif ($value === $highestValue) {
                $ids[] = $mood->cardId;
            }
        }

        return $ids;
    }
}
