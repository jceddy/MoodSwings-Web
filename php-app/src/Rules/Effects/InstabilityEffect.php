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
 * it to you, then you give them one of your moods." Nothing in that text
 * excludes Instability itself from "one of your moods" -- giving it away
 * is a legal answer, same as BetrayalEffect's own identical situation --
 * but at the moment the up-front choices panel is filled out, Instability
 * is still sitting in hand, not yet in play, so a field sourced from the
 * board at that point could never legally offer it. Modeled as a second
 * RequiresOpponentDecision step targeting the acting player themselves
 * (KEY_GIVEN, resolved only after Instability has actually entered play),
 * the same way Betrayal defers its own self-give choice -- the first step
 * (KEY_TAKEN) still targets the opponent, who genuinely does answer it
 * themselves, choosing which of the two candidates to give up.
 */
final class InstabilityEffect extends AbstractMoodEffect implements RequiresOpponentDecision
{
    private const CHOSEN_COUNT = 2;
    private const KEY_TAKEN = 'taken_mood_id';
    private const KEY_GIVEN = 'given_mood_id';

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
                key: self::KEY_TAKEN,
                targetPlayerId: $opponentId,
                decisionType: 'instability_choose_mood',
                field: [
                    'key' => self::KEY_TAKEN,
                    'type' => 'mood',
                    'candidate_card_ids' => $candidateMoodIds,
                    'required' => true,
                    'label' => 'Choose one of these two moods to give up',
                ],
            ),
            new PendingDecisionRequest(
                key: self::KEY_GIVEN,
                targetPlayerId: $playerId,
                decisionType: 'instability_give_mood',
                field: [
                    'key' => self::KEY_GIVEN,
                    'type' => 'mood',
                    'scope' => 'own',
                    'required' => true,
                    'label' => 'Choose one of your moods to give in exchange (Instability itself is a valid choice)',
                ],
            ),
        ];
    }

    public function resolveDecisions(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices, array $answers): void
    {
        if (!isset($answers[self::KEY_TAKEN])) {
            return;
        }

        $candidateMoodIds = $choices->ints('candidate_mood_ids');
        $takenCardId = $answers[self::KEY_TAKEN]->requireInt(self::KEY_TAKEN);
        if (!in_array($takenCardId, $candidateMoodIds, true)) {
            throw new InvalidChoiceException("Card {$takenCardId} was not one of the offered candidates");
        }

        $opponentId = $state->ownerOf($takenCardId);
        $state->giveInPlayToPlayer($takenCardId, $playerId);

        $givenCardId = $answers[self::KEY_GIVEN]->requireInt(self::KEY_GIVEN);
        if (!$state->isInPlay($givenCardId) || $state->ownerOf($givenCardId) !== $playerId || $givenCardId === $takenCardId) {
            throw new InvalidChoiceException("Card {$givenCardId} is not one of player {$playerId}'s other moods in play");
        }
        $state->giveInPlayToPlayer($givenCardId, $opponentId);
    }
}
