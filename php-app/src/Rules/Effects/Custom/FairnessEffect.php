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
 * Fairness: "At the start of each of your turns, suppress one of the highest value moods until the end
 * of this round." Automatic when one mood is strictly the highest; with a tie for highest, its owner
 * chooses which of the tied moods is suppressed.
 */
final class FairnessEffect extends AbstractMoodEffect implements TurnStartEffect
{
    private const KEY = 'target_mood_id';

    public function turnStartRequests(BoardState $state, int $cardId, int $playerId): ?array
    {
        $highest = $this->highestMoodIds($state);
        if (count($highest) <= 1) {
            return null;
        }

        return [new PendingDecisionRequest(
            key: self::KEY,
            targetPlayerId: $playerId,
            decisionType: 'turn_start_trigger',
            field: [
                'key' => self::KEY, 'type' => 'mood', 'scope' => 'any', 'required' => true,
                'label' => 'Fairness: choose which of the highest value moods to suppress until the end of the round',
                'candidate_card_ids' => $highest,
            ],
        )];
    }

    public function applyTurnStart(BoardState $state, int $cardId, int $playerId, array $answers): array
    {
        $highest = $this->highestMoodIds($state);
        if ($highest === []) {
            return [];
        }

        $targetCardId = isset($answers[self::KEY]) ? $answers[self::KEY]->requireInt(self::KEY) : $highest[0];
        if (!in_array($targetCardId, $highest, true)) {
            throw new InvalidChoiceException("Card {$targetCardId} is not one of the highest value moods");
        }
        $state->suppress($targetCardId, 'end_of_round', $cardId);

        return [];
    }

    /** @return int[] every mood tied for the highest current value, in play order */
    private function highestMoodIds(BoardState $state): array
    {
        $highestValue = null;
        $ids = [];
        foreach ($state->moodsInPlay() as $mood) {
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
