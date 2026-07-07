<?php

declare(strict_types=1);

namespace MoodSwings\Rules;

use LogicException;

/**
 * Computes each player's score for a round and settles the two questions
 * scoring answers: who won, and (in 3+ player games) who gets Hurt
 * Feelings for next round. The two use opposite tie-break directions per
 * the Extended Rules: ties for the win go to whoever played *earliest*
 * this round, but ties for Hurt Feelings go to whoever played *latest*.
 */
final class RoundScorer
{
    /** @return array<int, int> playerId => score */
    public function score(BoardState $state): array
    {
        $scores = array_fill_keys($state->playerOrder(), 0);
        foreach ($state->moodsInPlay() as $mood) {
            $scores[$mood->ownerId] += $state->valueOf($mood->cardId);
        }

        return $scores;
    }

    /**
     * @param array<int, int> $scores playerId => score
     * @param int[] $turnOrderThisRound the order players took their turns this round, earliest first
     */
    public function winner(array $scores, array $turnOrderThisRound): int
    {
        $highest = max($scores);
        foreach ($turnOrderThisRound as $playerId) {
            if ($scores[$playerId] === $highest) {
                return $playerId;
            }
        }

        throw new LogicException('No winner could be determined from the given scores and turn order');
    }

    /**
     * @param array<int, int> $scores playerId => score
     * @param int[] $turnOrderThisRound the order players took their turns this round, earliest first
     */
    public function hurtFeelings(array $scores, array $turnOrderThisRound): int
    {
        $lowest = min($scores);
        foreach (array_reverse($turnOrderThisRound) as $playerId) {
            if ($scores[$playerId] === $lowest) {
                return $playerId;
            }
        }

        throw new LogicException('No Hurt Feelings holder could be determined from the given scores and turn order');
    }
}
