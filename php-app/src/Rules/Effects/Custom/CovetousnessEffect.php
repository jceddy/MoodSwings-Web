<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects\Custom;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PendingDecisionRequest;
use MoodSwings\Rules\PlayerChoices;
use MoodSwings\Rules\TurnStartEffect;

/**
 * Covetousness: "At the start of each of your turns, you may choose one of your opponents' moods with a
 * value of 1 or less. It becomes yours."
 */
final class CovetousnessEffect extends AbstractMoodEffect implements TurnStartEffect
{
    private const KEY = 'target_mood_id';
    private const MAXIMUM_VALUE = 1;

    public function turnStartRequests(BoardState $state, int $cardId, int $playerId): ?array
    {
        $candidates = $this->candidates($state, $playerId);
        if ($candidates === []) {
            return [];
        }

        return [new PendingDecisionRequest(
            key: self::KEY,
            targetPlayerId: $playerId,
            decisionType: 'turn_start_trigger',
            field: [
                'key' => self::KEY, 'type' => 'mood', 'scope' => 'other', 'required' => false,
                'label' => "Covetousness: take one of your opponents' moods with a value of 1 or less",
                'candidate_card_ids' => $candidates,
            ],
        )];
    }

    public function applyTurnStart(BoardState $state, int $cardId, int $playerId, array $answers): array
    {
        $targetCardId = ($answers[self::KEY] ?? null)?->int(self::KEY);
        if ($targetCardId === null) {
            return [];
        }
        if (!in_array($targetCardId, $this->candidates($state, $playerId), true)) {
            throw new InvalidChoiceException("Card {$targetCardId} is not an opponent's mood with a value of 1 or less");
        }
        $state->giveInPlayToPlayer($targetCardId, $playerId);

        return [];
    }

    /** @return int[] */
    private function candidates(BoardState $state, int $playerId): array
    {
        $opponentIds = CustomEffectSupport::opponentIds($state, $playerId);
        $candidates = [];
        foreach ($state->moodsInPlay() as $mood) {
            if (in_array($mood->ownerId, $opponentIds, true) && $state->valueOf($mood->cardId) <= self::MAXIMUM_VALUE) {
                $candidates[] = $mood->cardId;
            }
        }

        return $candidates;
    }
}
