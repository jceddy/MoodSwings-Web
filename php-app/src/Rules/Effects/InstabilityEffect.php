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
 * Instability: "After playing this mood, you may choose two moods from
 * the same opponent. If you do, they choose one of those moods and give
 * it to you, then you give them one of your moods." The two candidates
 * are narrowed down by the acting player (given_mood_id, the mood handed
 * back in exchange, stays on the acting player's own choices bag exactly
 * as before -- see RequiresOpponentDecision), but which of the two
 * candidates the opponent gives up is genuinely their own decision.
 */
final class InstabilityEffect extends AbstractMoodEffect implements RequiresOpponentDecision
{
    private const CHOSEN_COUNT = 2;
    private const KEY = 'taken_mood_id';

    public function pendingDecisionsFor(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): array
    {
        $candidateMoodIds = $choices->ints('candidate_mood_ids');
        if ($candidateMoodIds === []) {
            return [];
        }
        if (count($candidateMoodIds) !== self::CHOSEN_COUNT || $candidateMoodIds[0] === $candidateMoodIds[1]) {
            throw new InvalidChoiceException('Instability requires choosing exactly two different moods');
        }
        foreach ($candidateMoodIds as $candidateCardId) {
            if (!$state->isInPlay($candidateCardId)) {
                throw new InvalidChoiceException("Card {$candidateCardId} is not in play");
            }
        }

        $opponentId = $state->ownerOf($candidateMoodIds[0]);
        if ($opponentId === $playerId || $state->ownerOf($candidateMoodIds[1]) !== $opponentId) {
            throw new InvalidChoiceException('Instability requires two moods owned by the same opponent');
        }

        return [
            new PendingDecisionRequest(
                key: self::KEY,
                targetPlayerId: $opponentId,
                decisionType: 'instability_choose_mood',
                field: [
                    'key' => self::KEY,
                    'type' => 'mood',
                    'candidate_card_ids' => $candidateMoodIds,
                    'required' => true,
                    'label' => 'Choose one of these two moods to give up',
                ],
            ),
        ];
    }

    public function resolveDecisions(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices, array $answers): void
    {
        if (!isset($answers[self::KEY])) {
            return;
        }

        $candidateMoodIds = $choices->ints('candidate_mood_ids');
        $takenCardId = $answers[self::KEY]->requireInt(self::KEY);
        if (!in_array($takenCardId, $candidateMoodIds, true)) {
            throw new InvalidChoiceException("Card {$takenCardId} was not one of the offered candidates");
        }

        $opponentId = $state->ownerOf($takenCardId);
        $state->giveInPlayToPlayer($takenCardId, $playerId);

        $givenCardId = $choices->requireInt('given_mood_id');
        if (!$state->isInPlay($givenCardId) || $state->ownerOf($givenCardId) !== $playerId || $givenCardId === $takenCardId) {
            throw new InvalidChoiceException("Card {$givenCardId} is not one of player {$playerId}'s other moods in play");
        }
        $state->giveInPlayToPlayer($givenCardId, $opponentId);
    }
}
