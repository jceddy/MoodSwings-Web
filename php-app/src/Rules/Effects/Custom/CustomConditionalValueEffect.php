<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects\Custom;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;

/**
 * "While in play -- This mood's value is <alt> if <condition>" for the custom set's value cards
 * that read the hands, the discard pile or the board (the colour-pair ones are
 * PairedColorThresholdEffect). One parameterized class instead of a dozen near-identical ones;
 * $condition names the test, $threshold its number where it has one. The mood's own owner is
 * "you".
 */
final class CustomConditionalValueEffect extends AbstractMoodEffect
{
    public const OWN_HAND_AT_LEAST = 'own_hand_at_least';
    public const OWN_HAND_MORE_THAN_EACH_OTHER = 'own_hand_more_than_each_other';
    public const OWN_HAND_FEWER_THAN_EACH_OTHER = 'own_hand_fewer_than_each_other';
    public const OWN_HAND_EMPTY = 'own_hand_empty';
    public const ANY_OPPONENT_HAND_EMPTY = 'any_opponent_hand_empty';
    public const DISCARD_PILE_AT_LEAST = 'discard_pile_at_least';
    public const EVEN_NUMBERED_MOODS_AT_LEAST = 'even_numbered_moods_at_least';
    public const OTHER_OWN_RED_MOODS_AT_LEAST = 'other_own_red_moods_at_least';
    public const OWN_ROUND_WINS_FEWER_THAN_EACH_OTHER = 'own_round_wins_fewer_than_each_other';
    public const ANY_OPPONENT_DISCARDED_FROM_HAND_THIS_ROUND = 'any_opponent_discarded_from_hand_this_round';
    public const DISTINCT_OWN_VALUES_AT_LEAST = 'distinct_own_values_at_least';

    public function __construct(private readonly string $condition, private readonly int $threshold = 0)
    {
    }

    public function computeValue(BoardState $state, int $cardId): int
    {
        $row = $state->catalogRow($state->effectiveCardId($cardId));

        return $this->conditionHolds($state, $cardId) ? $row['altValue'] : $row['baseValue'];
    }

    private function conditionHolds(BoardState $state, int $cardId): bool
    {
        $ownerId = $state->ownerOf($cardId);

        return match ($this->condition) {
            self::OWN_HAND_AT_LEAST => CustomEffectSupport::handCount($state, $ownerId) >= $this->threshold,
            self::OWN_HAND_MORE_THAN_EACH_OTHER => CustomEffectSupport::handSizeBeatsEachOther($state, $ownerId, fewer: false),
            self::OWN_HAND_FEWER_THAN_EACH_OTHER => CustomEffectSupport::handSizeBeatsEachOther($state, $ownerId, fewer: true),
            self::OWN_HAND_EMPTY => CustomEffectSupport::handCount($state, $ownerId) === 0,
            self::ANY_OPPONENT_HAND_EMPTY => $this->anyOpponentHandEmpty($state, $ownerId),
            self::DISCARD_PILE_AT_LEAST => count($state->discardPile()) >= $this->threshold,
            self::EVEN_NUMBERED_MOODS_AT_LEAST => $this->evenNumberedMoods($state) >= $this->threshold,
            self::OTHER_OWN_RED_MOODS_AT_LEAST => $this->otherOwnRedMoods($state, $cardId, $ownerId) >= $this->threshold,
            self::OWN_ROUND_WINS_FEWER_THAN_EACH_OTHER => CustomEffectSupport::hasWonFewerRoundsThanEachOther($state, $ownerId),
            self::ANY_OPPONENT_DISCARDED_FROM_HAND_THIS_ROUND => array_intersect($state->playersWhoDiscardedFromHandThisRound(), CustomEffectSupport::opponentIds($state, $ownerId)) !== [],
            self::DISTINCT_OWN_VALUES_AT_LEAST => $this->distinctOwnValues($state, $cardId, $ownerId) >= $this->threshold,
        };
    }

    /**
     * How many different values the owner's moods show (Restlessness). A mood whose own value reads this very
     * count -- this one, and any other copy of the same card -- is counted at its printed value, so the check
     * can't chase its own tail (a copy asking after the other would never finish); every other mood counts at
     * its current value, modifiers included.
     */
    private function distinctOwnValues(BoardState $state, int $cardId, int $ownerId): int
    {
        $ownKey = $state->catalogRow($state->effectiveCardId($cardId))['effectKey'];
        $values = [];
        foreach ($state->moodsOwnedBy($ownerId) as $mood) {
            $row = $state->catalogRow($state->effectiveCardId($mood->cardId));
            $values[$row['effectKey'] === $ownKey ? $row['baseValue'] : $state->valueOf($mood->cardId)] = true;
        }

        return count($values);
    }

    private function anyOpponentHandEmpty(BoardState $state, int $ownerId): bool
    {
        foreach (CustomEffectSupport::opponentIds($state, $ownerId) as $opponentId) {
            if (CustomEffectSupport::handCount($state, $opponentId) === 0) {
                return true;
            }
        }

        return false;
    }

    /** Moods (any owner, this one included) whose printed number is even -- 0 is even. */
    private function evenNumberedMoods(BoardState $state): int
    {
        $count = 0;
        foreach ($state->moodsInPlay() as $mood) {
            if (CustomEffectSupport::printedNumber($state, $mood->cardId) % 2 === 0) {
                $count++;
            }
        }

        return $count;
    }

    private function otherOwnRedMoods(BoardState $state, int $cardId, int $ownerId): int
    {
        $count = 0;
        foreach ($state->moodsOwnedBy($ownerId) as $mood) {
            if ($mood->cardId !== $cardId && $state->colorOf($mood->cardId) === 'red') {
                $count++;
            }
        }

        return $count;
    }
}
