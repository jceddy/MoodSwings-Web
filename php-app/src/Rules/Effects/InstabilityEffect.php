<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/**
 * Instability: "After playing this mood, you may choose two moods from
 * the same opponent. If you do, they choose one of those moods and give
 * it to you, then you give them one of your moods." The two candidate
 * moods are already narrowed down by the acting player and are public
 * information, so which one the opponent "chooses" is resolved randomly
 * here rather than needing their input -- consistent with Malice.
 */
final class InstabilityEffect extends AbstractMoodEffect
{
    private const CHOSEN_COUNT = 2;

    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $candidateMoodIds = $choices->ints('candidate_mood_ids');
        if ($candidateMoodIds === []) {
            return;
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

        $takenCardId = $candidateMoodIds[array_rand($candidateMoodIds)];
        $state->giveInPlayToPlayer($takenCardId, $playerId);

        $givenCardId = $choices->requireInt('given_mood_id');
        if (!$state->isInPlay($givenCardId) || $state->ownerOf($givenCardId) !== $playerId || $givenCardId === $takenCardId) {
            throw new InvalidChoiceException("Card {$givenCardId} is not one of player {$playerId}'s other moods in play");
        }
        $state->giveInPlayToPlayer($givenCardId, $opponentId);
    }
}
