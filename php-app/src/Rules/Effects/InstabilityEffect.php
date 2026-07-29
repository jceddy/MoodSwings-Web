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
 * excludes giving the very same mood straight back -- "one of your
 * moods" includes it the instant it's actually yours, same as
 * BetrayalEffect's own "may give itself away" situation -- but that's
 * only true AFTER the first exchange has genuinely happened, matching the
 * card's own "then" ordering. Modeled as two sequential
 * RequiresOpponentDecision rounds rather than one parallel batch: round 1
 * (KEY_TAKEN, targeting the opponent) asks which of the two candidates to
 * give up; resolveDecisions() applies that transfer and returns a
 * follow-up KEY_GIVEN request (targeting the acting player themselves,
 * deferred for the same "not resolvable until the board reflects it yet"
 * reason Betrayal/Pride defer their own self-targeting choices) only once
 * the taken mood is actually the acting player's own -- so it's a
 * genuinely legal, offerable answer to "one of your moods", not merely an
 * unblocked exclusion.
 *
 * The two rounds are told apart purely by which key $answers contains --
 * round 2 never re-submits KEY_TAKEN, so $opponentId (no longer present
 * in round 2's own answers) is recovered by elimination over this
 * invocation's own `candidate_mood_ids` choice: whichever of the two
 * original candidates ISN'T owned by the acting player once round 1 has
 * applied is necessarily still the opponent's, since nothing else can
 * interleave and change that card's ownership mid-resolution (one open
 * decision batch per game at a time, under the same per-game lock this
 * whole pause/resume mechanism already relies on).
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
        ];
    }

    public function resolveDecisions(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices, array $answers): array
    {
        if (isset($answers[self::KEY_GIVEN])) {
            return $this->resolveGivenMood($state, $playerId, $choices, $answers);
        }

        if (!isset($answers[self::KEY_TAKEN])) {
            return [];
        }

        return $this->resolveTakenMood($state, $playerId, $choices, $answers);
    }

    /** @param array<string, PlayerChoices> $answers */
    private function resolveTakenMood(BoardState $state, int $playerId, PlayerChoices $choices, array $answers): array
    {
        $candidateMoodIds = $choices->ints('candidate_mood_ids');
        $takenCardId = $answers[self::KEY_TAKEN]->requireInt(self::KEY_TAKEN);
        if (!in_array($takenCardId, $candidateMoodIds, true)) {
            throw new InvalidChoiceException("Card {$takenCardId} was not one of the offered candidates");
        }

        $state->giveInPlayToPlayer($takenCardId, $playerId);

        return [
            new PendingDecisionRequest(
                key: self::KEY_GIVEN,
                targetPlayerId: $playerId,
                decisionType: 'instability_give_mood',
                field: [
                    'key' => self::KEY_GIVEN,
                    'type' => 'mood',
                    'scope' => 'own',
                    'required' => true,
                    'label' => 'Choose one of your moods to give in exchange (the mood you just received, or Instability itself, are valid choices)',
                ],
            ),
        ];
    }

    /** @param array<string, PlayerChoices> $answers */
    private function resolveGivenMood(BoardState $state, int $playerId, PlayerChoices $choices, array $answers): array
    {
        $opponentId = $playerId;
        foreach ($choices->ints('candidate_mood_ids') as $candidateCardId) {
            if ($state->ownerOf($candidateCardId) !== $playerId) {
                $opponentId = $state->ownerOf($candidateCardId);
                break;
            }
        }

        $givenCardId = $answers[self::KEY_GIVEN]->requireInt(self::KEY_GIVEN);
        if (!$state->isInPlay($givenCardId) || $state->ownerOf($givenCardId) !== $playerId) {
            throw new InvalidChoiceException("Card {$givenCardId} is not one of player {$playerId}'s moods in play");
        }
        $state->giveInPlayToPlayer($givenCardId, $opponentId);

        return [];
    }
}
