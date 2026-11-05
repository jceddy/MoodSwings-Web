<?php

declare(strict_types=1);

namespace MoodSwings\Rules;

/**
 * Implemented by moods that can win the round outright once it has been scored (Unity: "after scoring, if
 * you have five or more moods that share a color, you win the round"). GameService::determineRoundWinner()
 * asks every such mood in play; if any owner qualifies, the winner is chosen among the qualifying owners
 * (their teams, in a team format) instead of by score alone.
 */
interface RoundWinEffect
{
    public function winsRoundAfterScoring(BoardState $state, int $cardId, int $ownerId): bool;
}
