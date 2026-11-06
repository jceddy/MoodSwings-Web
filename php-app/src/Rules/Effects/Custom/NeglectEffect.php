<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects\Custom;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BeforeScoringEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PendingDecisionRequest;

/**
 * Neglect: "While in play -- Before scoring, each opponent with no cards in their hand puts one of their highest value
 * moods into the discard pile." Fires when the round is about to be scored (BeforeScoringEffect), so the discarded moods
 * don't count. An opponent with a single highest value mood loses it automatically; one tied for highest chooses which of
 * the tied moods goes. Which opponents qualify is fixed when the trigger fires.
 */
final class NeglectEffect extends AbstractMoodEffect implements BeforeScoringEffect
{
    private const KEY_PREFIX = 'discard_mood_id_';

    public function beforeScoringRequests(BoardState $state, int $cardId, int $playerId): ?array
    {
        $qualifying = $this->qualifyingOpponents($state, $playerId);
        if ($qualifying === []) {
            return [];
        }

        $requests = [];
        foreach ($qualifying as $opponentId) {
            $candidates = $this->highestValueMoodIds($state, $opponentId);
            if (count($candidates) > 1) {
                $requests[] = new PendingDecisionRequest(
                    key: self::KEY_PREFIX . $opponentId,
                    targetPlayerId: $opponentId,
                    decisionType: 'before_scoring_trigger',
                    field: [
                        'key' => self::KEY_PREFIX . $opponentId, 'type' => 'mood', 'scope' => 'own', 'required' => true,
                        'label' => 'Neglect: put one of your highest value moods into the discard pile',
                        'candidate_card_ids' => $candidates,
                    ],
                );
            }
        }

        // Nobody has a tie to break: every qualifying opponent just loses their one highest value mood.
        return $requests === [] ? null : $requests;
    }

    public function applyBeforeScoring(BoardState $state, int $cardId, int $playerId, array $answers): array
    {
        foreach ($this->qualifyingOpponents($state, $playerId) as $opponentId) {
            $candidates = $this->highestValueMoodIds($state, $opponentId);
            $key = self::KEY_PREFIX . $opponentId;
            if (isset($answers[$key])) {
                $moodId = $answers[$key]->requireInt($key);
                if (!in_array($moodId, $candidates, true)) {
                    throw new InvalidChoiceException("Card {$moodId} is not one of player {$opponentId}'s highest value moods");
                }
            } else {
                $moodId = $candidates[0];
            }
            $state->moveInPlayToDiscard($moodId);
        }

        return [];
    }

    /** @return int[] opponents with no cards in hand and a mood in play */
    private function qualifyingOpponents(BoardState $state, int $playerId): array
    {
        return array_values(array_filter(
            CustomEffectSupport::opponentIds($state, $playerId),
            static fn (int $opponentId): bool => $state->hand($opponentId) === [] && $state->moodsOwnedBy($opponentId) !== [],
        ));
    }

    /** @return int[] */
    private function highestValueMoodIds(BoardState $state, int $ownerId): array
    {
        $highestValue = null;
        foreach ($state->moodsOwnedBy($ownerId) as $mood) {
            $highestValue = max($highestValue ?? PHP_INT_MIN, $state->valueOf($mood->cardId));
        }

        $candidates = [];
        foreach ($state->moodsOwnedBy($ownerId) as $mood) {
            if ($state->valueOf($mood->cardId) === $highestValue) {
                $candidates[] = $mood->cardId;
            }
        }

        return $candidates;
    }
}
